<?php

declare(strict_types=1);

namespace App\Libraries\Http;

use App\Exceptions\MultipartException;
use App\Exceptions\PayloadTooLargeException;
use Throwable;

/**
 * Streams a multipart/form-data body out of a raw request stream.
 *
 * This exists because PHP only populates $_FILES for POST requests, and the
 * NuGet publish endpoint is a PUT. Nothing in the engine will parse that body,
 * so we do it here — reading php://input in fixed-size chunks and writing the
 * file part straight to disk.
 *
 * Never read the body into a string first. On shared hosting a package of a
 * few tens of megabytes is entirely normal and memory_limit rarely generous;
 * file_get_contents('php://input') turns a routine push into a fatal error.
 *
 * The parser is deliberately tolerant on the shapes real clients send, as
 * observed in the captured fixture from `dotnet nuget push`:
 *   - the boundary arrives quoted in the Content-Type header;
 *   - Content-Disposition parameters arrive unquoted;
 *   - Content-Type precedes Content-Disposition, so header order is not fixed;
 *   - filename is sent twice, plainly and RFC 5987 encoded.
 */
final class MultipartPutParser
{
    /**
     * RFC 2046 caps a boundary at 70 characters.
     */
    private const MAX_BOUNDARY_LENGTH = 70;

    public function __construct(
        private readonly int $maxBytes,
        private readonly string $temporaryDirectory,
        private readonly int $maxFieldBytes = 65536,
        private readonly int $chunkSize = 262144,
    ) {
    }

    /**
     * Extracts the boundary from a Content-Type header value.
     */
    public static function boundaryFrom(string $contentType): ?string
    {
        if (! str_starts_with(strtolower(trim($contentType)), strtolower('multipart/'))) {
            return null;
        }

        if (preg_match('/;\s*boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m) !== 1) {
            return null;
        }

        $boundary = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

        if ($boundary === '' || strlen($boundary) > self::MAX_BOUNDARY_LENGTH) {
            return null;
        }

        return $boundary;
    }

    /**
     * @param resource $stream
     */
    public function parse($stream, string $contentType): MultipartBody
    {
        if (! str_starts_with(strtolower(trim($contentType)), strtolower('multipart/'))) {
            throw MultipartException::notMultipart($contentType);
        }

        $boundary = self::boundaryFrom($contentType);

        if ($boundary === null) {
            throw MultipartException::missingBoundary();
        }

        $state = new class () {
            public string $buffer = '';
            public int $consumed  = 0;
            public bool $eof      = false;

            /**
             * @var list<MultipartPart>
             */
            public array $parts = [];
        };

        try {
            return $this->run($stream, $boundary, $state);
        } catch (MultipartException|PayloadTooLargeException $e) {
            // Do not leave half-written uploads behind on the way out.
            (new MultipartBody($state->parts, $state->consumed))->cleanup();

            throw $e;
        }
    }

    /**
     * @param resource $stream
     */
    private function run($stream, string $boundary, object $state): MultipartBody
    {
        // Every boundary in the body is preceded by CRLF, including the first
        // one — which has none before it. Pretending it does lets a single
        // delimiter handle every case.
        $delimiter     = "\r\n--" . $boundary;
        $state->buffer = "\r\n";

        $this->seek($stream, $state, $delimiter, 'the first boundary');

        while (true) {
            $this->fillTo($stream, $state, 2);

            $next = substr($state->buffer, 0, 2);

            if ($next === '--') {
                break;
            }

            if ($next !== "\r\n") {
                // Tolerate the transport padding RFC 2046 permits.
                $trimmed = ltrim($state->buffer, " \t");

                if (! str_starts_with($trimmed, "\r\n")) {
                    throw MultipartException::malformed('a boundary is not followed by CRLF');
                }

                $state->buffer = $trimmed;
            }

            $state->buffer = substr($state->buffer, 2);

            $headers        = $this->readHeaders($stream, $state);
            $state->parts[] = $this->readPart($stream, $state, $delimiter, $headers);
        }

        return new MultipartBody($state->parts, $state->consumed);
    }

    /**
     * Discards everything up to and including the next delimiter.
     *
     * @param resource $stream
     */
    private function seek($stream, object $state, string $delimiter, string $what): void
    {
        while (true) {
            $position = strpos($state->buffer, $delimiter);

            if ($position !== false) {
                $state->buffer = substr($state->buffer, $position + strlen($delimiter));

                return;
            }

            // Keep enough tail for a delimiter straddling two chunks.
            $keep = strlen($delimiter) - 1;

            if (strlen($state->buffer) > $keep) {
                $state->buffer = substr($state->buffer, -$keep);
            }

            if (! $this->pump($stream, $state)) {
                throw MultipartException::truncated($what);
            }
        }
    }

    /**
     * @param resource $stream
     *
     * @return array<string, string> lower-cased header names
     */
    private function readHeaders($stream, object $state): array
    {
        while (($end = strpos($state->buffer, "\r\n\r\n")) === false) {
            if (strlen($state->buffer) > $this->maxFieldBytes) {
                throw MultipartException::malformed('part headers are unreasonably long');
            }

            if (! $this->pump($stream, $state)) {
                throw MultipartException::truncated('the headers of a part');
            }
        }

        $raw           = substr($state->buffer, 0, $end);
        $state->buffer = substr($state->buffer, $end + 4);

        $headers = [];

        foreach (explode("\r\n", $raw) as $line) {
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value]                   = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    /**
     * @param resource              $stream
     * @param array<string, string> $headers
     */
    private function readPart($stream, object $state, string $delimiter, array $headers): MultipartPart
    {
        $disposition = self::parseParameters($headers['content-disposition'] ?? '');
        $name        = $disposition['name'] ?? '';
        $fileName    = $disposition['filename*'] ?? $disposition['filename'] ?? null;
        $isFile      = $fileName !== null;

        $handle = null;
        $path   = null;
        $value  = '';
        $size   = 0;

        if ($isFile) {
            $path = $this->createTemporaryFile();

            $handle = fopen($path, 'wb');

            if ($handle === false) {
                throw MultipartException::cannotBuffer($path);
            }
        }

        try {
            while (true) {
                $position = strpos($state->buffer, $delimiter);

                if ($position !== false) {
                    $size += $this->emit(substr($state->buffer, 0, $position), $handle, $value, $name);
                    $state->buffer = substr($state->buffer, $position + strlen($delimiter));

                    break;
                }

                $keep = strlen($delimiter) - 1;

                if (strlen($state->buffer) > $keep) {
                    $flush         = substr($state->buffer, 0, strlen($state->buffer) - $keep);
                    $state->buffer = substr($state->buffer, -$keep);
                    $size += $this->emit($flush, $handle, $value, $name);
                }

                if (! $this->pump($stream, $state)) {
                    throw MultipartException::truncated(sprintf('the body of part "%s"', $name));
                }
            }
        } catch (Throwable $e) {
            // This part never makes it into $state->parts on the way out, so
            // the caller's cleanup (built from completed parts only) can never
            // reach a temporary file abandoned mid-write — it has to go here.
            if ($handle !== null) {
                fclose($handle);
            }

            if ($path !== null) {
                @unlink($path);
            }

            throw $e;
        }

        if ($handle !== null) {
            fclose($handle);
        }

        return new MultipartPart(
            $name,
            $fileName,
            $headers['content-type'] ?? null,
            $isFile ? null : $value,
            $path,
            $size,
        );
    }

    /**
     * @param resource|null $handle
     */
    private function emit(string $chunk, $handle, string &$value, string $name): int
    {
        if ($chunk === '') {
            return 0;
        }

        if ($handle !== null) {
            fwrite($handle, $chunk);

            return strlen($chunk);
        }

        if (strlen($value) + strlen($chunk) > $this->maxFieldBytes) {
            throw PayloadTooLargeException::forField($name, $this->maxFieldBytes);
        }

        $value .= $chunk;

        return strlen($chunk);
    }

    /**
     * Reads one chunk from the stream. Returns false at end of input.
     *
     * @param resource $stream
     */
    private function pump($stream, object $state): bool
    {
        if ($state->eof) {
            return false;
        }

        $chunk = fread($stream, $this->chunkSize);

        if ($chunk === false || $chunk === '') {
            $state->eof = true;

            return false;
        }

        $state->consumed += strlen($chunk);

        if ($state->consumed > $this->maxBytes) {
            throw new PayloadTooLargeException($this->maxBytes);
        }

        $state->buffer .= $chunk;

        if (feof($stream)) {
            $state->eof = true;
        }

        return true;
    }

    /**
     * @param resource $stream
     */
    private function fillTo($stream, object $state, int $length): void
    {
        while (strlen($state->buffer) < $length) {
            if (! $this->pump($stream, $state)) {
                throw MultipartException::truncated('a boundary');
            }
        }
    }

    private function createTemporaryFile(): string
    {
        if (! is_dir($this->temporaryDirectory) && ! mkdir($this->temporaryDirectory, 0o775, true)) {
            throw MultipartException::cannotBuffer($this->temporaryDirectory);
        }

        $path = tempnam($this->temporaryDirectory, 'pepite-upload-');

        if ($path === false) {
            throw MultipartException::cannotBuffer($this->temporaryDirectory);
        }

        return $path;
    }

    /**
     * Splits a header value's parameters, quoted or bare.
     *
     * RFC 5987's `filename*` wins over plain `filename` when both are present,
     * which is exactly what the .NET client sends.
     *
     * @return array<string, string>
     */
    private static function parseParameters(string $header): array
    {
        $parameters = [];

        preg_match_all(
            '/(?:^|;)\s*([A-Za-z0-9\-]+\*?)\s*=\s*(?:"([^"]*)"|([^";]*))/',
            $header,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $key   = strtolower($match[1]);
            $value = trim($match[2] !== '' ? $match[2] : ($match[3] ?? ''));

            if ($value === '') {
                continue;
            }

            if (str_ends_with($key, '*')) {
                $value = self::decodeExtendedValue($value);
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * Decodes the RFC 5987 form: charset'language'percent-encoded-value.
     */
    private static function decodeExtendedValue(string $value): string
    {
        $segments = explode("'", $value, 3);

        return rawurldecode($segments[2] ?? $value);
    }
}
