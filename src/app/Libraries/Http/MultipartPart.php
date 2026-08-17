<?php

declare(strict_types=1);

namespace App\Libraries\Http;

/**
 * One part of a parsed multipart body.
 *
 * A file part is on disk and never in memory; a plain field is in memory and
 * capped. The two are distinguished by the presence of a filename, as the
 * client declared it.
 */
final class MultipartPart
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $fileName,
        public readonly ?string $contentType,
        public readonly ?string $value,
        public readonly ?string $path,
        public readonly int $size,
    ) {
    }

    public function isFile(): bool
    {
        return $this->path !== null;
    }

    /**
     * The client-supplied file name.
     *
     * Never build a path from it. `dotnet nuget push` always sends
     * "package.nupkg" regardless of the real file, and a hostile client can
     * send anything at all; the stored name comes from the .nuspec instead.
     */
    public function declaredFileName(): ?string
    {
        return $this->fileName;
    }
}
