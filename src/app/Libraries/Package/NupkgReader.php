<?php

declare(strict_types=1);

namespace App\Libraries\Package;

use App\Exceptions\InvalidPackageException;
use ZipArchive;

/**
 * Reads a .nupkg from disk.
 *
 * A .nupkg is an OPC package: a zip holding exactly one .nuspec at its root,
 * some OPC bookkeeping, and the payload. Everything in it is content supplied
 * by whoever pushed the package, so entry names are validated before any of
 * them reaches the filesystem.
 */
final class NupkgReader
{
    /**
     * Generous for a .nuspec, an icon or a readme, and small enough that a
     * hostile archive cannot exhaust memory through them.
     */
    public const DEFAULT_ENTRY_LIMIT = 4 * 1024 * 1024;

    /**
     * OPC bookkeeping, present in every package and interesting to no one.
     */
    private const OPC_PREFIXES = ['_rels/', 'package/services/metadata/'];

    private const OPC_FILES = ['[Content_Types].xml', '.signature.p7s'];

    /**
     * @var list<string>
     */
    private array $entries;

    private ?string $nuspecEntry       = null;
    private ?PackageMetadata $metadata = null;

    private function __construct(
        private readonly string $path,
        private ?ZipArchive $zip,
    ) {
        $this->entries = $this->collectEntries();
    }

    public static function open(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidPackageException::unreadableArchive($path, 'no such readable file');
        }

        $zip    = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw InvalidPackageException::unreadableArchive(
                $path,
                sprintf('not a valid zip archive (code %d)', (int) $opened),
            );
        }

        return new self($path, $zip);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sizeBytes(): int
    {
        return (int) filesize($this->path);
    }

    /**
     * The package hash, base64 encoded SHA-512 — the form NuGet publishes.
     */
    public function sha512Base64(): string
    {
        return base64_encode(hash_file('sha512', $this->path, true));
    }

    /**
     * Every entry, in archive order.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Entries excluding OPC bookkeeping — what the package actually ships.
     *
     * @return list<string>
     */
    public function contentEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static function (string $entry): bool {
                foreach (self::OPC_FILES as $file) {
                    if (strcasecmp($entry, $file) === 0) {
                        return false;
                    }
                }

                foreach (self::OPC_PREFIXES as $prefix) {
                    if (str_starts_with(strtolower($entry), strtolower($prefix))) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    public function nuspecEntry(): string
    {
        if ($this->nuspecEntry !== null) {
            return $this->nuspecEntry;
        }

        $found = array_values(array_filter(
            $this->entries,
            static fn (string $entry): bool => preg_match('/^[^\/]+\.nuspec$/i', $entry) === 1,
        ));

        if ($found === []) {
            throw InvalidPackageException::missingNuspec();
        }

        if (count($found) > 1) {
            throw InvalidPackageException::ambiguousNuspec(count($found));
        }

        return $this->nuspecEntry = $found[0];
    }

    public function nuspecXml(): string
    {
        return $this->readEntry($this->nuspecEntry());
    }

    public function metadata(): PackageMetadata
    {
        return $this->metadata ??= (new NuspecParser())->parse($this->nuspecXml());
    }

    public function hasEntry(string $logicalPath): bool
    {
        return $this->findEntry($logicalPath) !== null;
    }

    /**
     * Resolves a path as written in the .nuspec to a real archive entry.
     *
     * Three mismatches have to be absorbed: MSBuild writes Windows separators
     * into the nuspec, OPC percent-encodes entry names in the archive, and the
     * two do not always agree on case.
     */
    public function findEntry(string $logicalPath): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($logicalPath)), '/');

        if ($normalized === '') {
            return null;
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $normalized)));

        foreach ([$normalized, $encoded] as $candidate) {
            if (in_array($candidate, $this->entries, true)) {
                return $candidate;
            }
        }

        foreach ($this->entries as $entry) {
            foreach ([$normalized, $encoded] as $candidate) {
                if (strcasecmp($entry, $candidate) === 0) {
                    return $entry;
                }
            }
        }

        return null;
    }

    public function readEntry(string $entry, int $maxBytes = self::DEFAULT_ENTRY_LIMIT): string
    {
        $zip  = $this->zip();
        $stat = $zip->statName($entry);

        if ($stat === false) {
            throw InvalidPackageException::entryNotFound($entry);
        }

        if ($stat['size'] > $maxBytes) {
            throw InvalidPackageException::entryTooLarge($entry, $maxBytes);
        }

        $contents = $zip->getFromName($entry, $maxBytes + 1);

        if ($contents === false) {
            throw InvalidPackageException::entryNotFound($entry);
        }

        // The declared size is metadata and can lie; the decompressed length is
        // what actually lands in memory.
        if (strlen($contents) > $maxBytes) {
            throw InvalidPackageException::entryTooLarge($entry, $maxBytes);
        }

        return $contents;
    }

    /**
     * Copies an entry to disk in chunks, never holding it whole in memory.
     */
    public function extractEntry(string $entry, string $targetPath, int $maxBytes): void
    {
        $zip = $this->zip();

        if ($zip->statName($entry) === false) {
            throw InvalidPackageException::entryNotFound($entry);
        }

        $source = $zip->getStream($entry);

        if ($source === false) {
            throw InvalidPackageException::entryNotFound($entry);
        }

        $target = fopen($targetPath, 'wb');

        if ($target === false) {
            fclose($source);

            throw InvalidPackageException::unreadableArchive(
                $this->path,
                sprintf('cannot write to "%s"', $targetPath),
            );
        }

        $written = 0;

        try {
            while (! feof($source)) {
                $chunk = fread($source, 262144);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    throw InvalidPackageException::entryTooLarge($entry, $maxBytes);
                }

                fwrite($target, $chunk);
            }
        } catch (InvalidPackageException $e) {
            fclose($target);
            @unlink($targetPath);

            throw $e;
        } finally {
            fclose($source);

            if (is_resource($target)) {
                fclose($target);
            }
        }
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    /**
     * @return list<string>
     */
    private function collectEntries(): array
    {
        $zip     = $this->zip();
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            // Directory entries carry no payload.
            if (str_ends_with($name, '/')) {
                continue;
            }

            self::assertSafeEntryName($name);

            $entries[] = $name;
        }

        return $entries;
    }

    /**
     * Rejects entry names that could escape the extraction directory.
     *
     * Zip slip is the reason: an archive is free to name an entry
     * "../../public/index.php", and PHP will happily write it there.
     */
    private static function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw InvalidPackageException::unsafeEntry($name);
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw InvalidPackageException::unsafeEntry($name);
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw InvalidPackageException::unsafeEntry($name);
            }
        }
    }

    private function zip(): ZipArchive
    {
        if ($this->zip === null) {
            throw InvalidPackageException::unreadableArchive($this->path, 'the archive is closed');
        }

        return $this->zip;
    }
}
