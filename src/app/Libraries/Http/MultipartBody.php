<?php

declare(strict_types=1);

namespace App\Libraries\Http;

/**
 * The outcome of parsing a multipart body.
 *
 * Owns the temporary files behind its file parts: callers must call cleanup()
 * in a finally block, whether the request succeeded or not. On shared hosting
 * an orphaned upload is a quota problem within days.
 */
final class MultipartBody
{
    /**
     * @param list<MultipartPart> $parts
     */
    public function __construct(
        private readonly array $parts,
        public readonly int $totalBytes,
    ) {
    }

    /**
     * @return list<MultipartPart>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    public function part(string $name): ?MultipartPart
    {
        foreach ($this->parts as $part) {
            if ($part->name === $name) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The first file part, whatever its field name.
     *
     * `dotnet nuget push` names it "package", but other clients do not all
     * agree, and the endpoint only ever expects one file.
     */
    public function firstFile(): ?MultipartPart
    {
        foreach ($this->parts as $part) {
            if ($part->isFile()) {
                return $part;
            }
        }

        return null;
    }

    public function cleanup(): void
    {
        foreach ($this->parts as $part) {
            if ($part->path !== null && is_file($part->path)) {
                @unlink($part->path);
            }
        }
    }
}
