<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Package\NupkgReader;
use App\Libraries\Package\PackageMetadata;
use App\Libraries\Version\NuGetVersion;
use RuntimeException;

/**
 * Package blobs on disk.
 *
 * Everything lives under a single root outside the web root, laid out by feed,
 * folded identifier and folded version:
 *
 *     packages/{feed}/{id}/{version}/{id}.{version}.nupkg
 *                                   /{id}.nuspec
 *                                   /icon.png
 *                                   /readme.md
 *
 * Paths recorded in the database are relative to that root, so moving the
 * storage — or restoring it elsewhere — needs no data migration.
 */
final class PackageStorage
{
    public function __construct(
        private readonly string $root,
        private readonly int $maxAssetBytes,
    ) {
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Directory for one version, relative to the root.
     */
    public function directoryFor(int $feedId, string $packageId, NuGetVersion $version): string
    {
        return sprintf(
            'packages/%d/%s/%s',
            $feedId,
            strtolower($packageId),
            $version->normalizedLower(),
        );
    }

    public function absolute(string $relativePath): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolute($relativePath));
    }

    /**
     * Writes the package and its extracted assets.
     *
     * Returns the relative paths to record. The caller is responsible for
     * calling discard() if the surrounding transaction does not commit: the
     * filesystem takes no part in it.
     *
     * @return array{directory: string, nupkg: string, nuspec: string, icon: string|null, readme: string|null}
     */
    public function store(
        int $feedId,
        NupkgReader $reader,
        PackageMetadata $metadata,
        string $sourcePath,
    ): array {
        $directory = $this->directoryFor($feedId, $metadata->id, $metadata->version);
        $this->makeDirectory($directory);

        $base = sprintf('%s.%s', $metadata->idLower(), $metadata->version->normalizedLower());

        $nupkg = $directory . '/' . $base . '.nupkg';
        $this->copyInto($sourcePath, $nupkg);

        // Kept verbatim rather than re-serialised: the flat container has to
        // serve back the exact bytes the author published.
        $nuspec = $directory . '/' . $metadata->idLower() . '.nuspec';
        $this->write($nuspec, $reader->nuspecXml());

        return [
            'directory' => $directory,
            'nupkg'     => $nupkg,
            'nuspec'    => $nuspec,
            'icon'      => $this->extractAsset($reader, $metadata->icon, $directory, 'icon'),
            'readme'    => $this->extractAsset($reader, $metadata->readme, $directory, 'readme'),
        ];
    }

    /**
     * Stores a symbol package beside the version it belongs to.
     *
     * Symbols are kept, never served: serving them means parsing the binary
     * header of a portable PDB, and that cannot be validated without a real
     * debugger on the other end (PLAN.md 4.4).
     */
    public function storeSymbols(
        int $feedId,
        string $packageId,
        NuGetVersion $version,
        string $sourcePath,
    ): string {
        $directory = $this->directoryFor($feedId, $packageId, $version);
        $this->makeDirectory($directory);

        $relative = sprintf(
            '%s/%s.%s.snupkg',
            $directory,
            strtolower($packageId),
            $version->normalizedLower(),
        );

        $this->copyInto($sourcePath, $relative);

        return $relative;
    }

    /**
     * Removes a version's directory. Used to undo a failed publication.
     */
    public function discard(string $relativeDirectory): void
    {
        $absolute = $this->absolute($relativeDirectory);

        if (! is_dir($absolute)) {
            return;
        }

        foreach ((array) glob($absolute . DIRECTORY_SEPARATOR . '*') as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($absolute);
    }

    public function readStream(string $relativePath)
    {
        $handle = @fopen($this->absolute($relativePath), 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Stored file "%s" is missing.', $relativePath));
        }

        return $handle;
    }

    /**
     * Extracts an embedded icon or readme next to the package.
     *
     * A missing or oversized asset is not worth failing a publication over —
     * the package itself is fine, and the catalogue simply shows no image.
     */
    private function extractAsset(
        NupkgReader $reader,
        ?string $declaredPath,
        string $directory,
        string $kind,
    ): ?string {
        if ($declaredPath === null) {
            return null;
        }

        $entry = $reader->findEntry($declaredPath);

        if ($entry === null) {
            return null;
        }

        $extension = strtolower(pathinfo($declaredPath, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';

        $relative = sprintf('%s/%s.%s', $directory, $kind, $extension);

        try {
            $reader->extractEntry($entry, $this->absolute($relative), $this->maxAssetBytes);
        } catch (RuntimeException) {
            return null;
        }

        return $relative;
    }

    private function makeDirectory(string $relativeDirectory): void
    {
        $absolute = $this->absolute($relativeDirectory);

        if (! is_dir($absolute) && ! mkdir($absolute, 0o775, true) && ! is_dir($absolute)) {
            throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $absolute));
        }
    }

    private function copyInto(string $sourcePath, string $relativeTarget): void
    {
        if (! @copy($sourcePath, $this->absolute($relativeTarget))) {
            throw new RuntimeException(sprintf('Cannot write "%s" to storage.', $relativeTarget));
        }
    }

    private function write(string $relativeTarget, string $contents): void
    {
        if (@file_put_contents($this->absolute($relativeTarget), $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s" to storage.', $relativeTarget));
        }
    }
}
