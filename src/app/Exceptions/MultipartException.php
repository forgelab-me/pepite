<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The request body could not be parsed as multipart/form-data.
 *
 * Distinct from PayloadTooLargeException because the two map to different
 * status codes: a malformed body is a 400, an oversized one a 413.
 */
final class MultipartException extends RuntimeException
{
    public static function notMultipart(string $contentType): self
    {
        return new self(sprintf(
            'Expected a multipart/form-data body, got "%s".',
            $contentType === '' ? '(none)' : $contentType,
        ));
    }

    public static function missingBoundary(): self
    {
        return new self('The Content-Type header declares no usable multipart boundary.');
    }

    public static function truncated(string $where): self
    {
        return new self(sprintf('The request body ended unexpectedly while reading %s.', $where));
    }

    public static function malformed(string $reason): self
    {
        return new self(sprintf('Malformed multipart body: %s.', $reason));
    }

    public static function cannotBuffer(string $path): self
    {
        return new self(sprintf('Cannot open a temporary file at "%s".', $path));
    }
}
