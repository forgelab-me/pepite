<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Exceptions\StorageConflictException;
use App\Libraries\Package\NupkgReader;
use App\Libraries\Package\PackageMetadata;
use App\Libraries\Version\NuGetVersion;
use RuntimeException;
use Throwable;

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
 *
 * A version directory is built under packages/{feed}/.incoming-{random}/ and
 * moved into place with a single rename (see store()/claim()): the move is
 * what decides which of two concurrent pushes owns the directory.
 */
final class PackageStorage
{
    /**
     * How long a version directory with no committed row behind it is
     * assumed to belong to a push still in flight. A push is seconds of
     * extraction and one transaction; ten minutes is far past that.
     */
    private const ORPHAN_GRACE_SECONDS = 600;

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
        $base      = sprintf('%s.%s', $metadata->idLower(), $metadata->version->normalizedLower());

        // Everything is written into a private staging directory and moved
        // into place in one step at the end. Writing straight into the final
        // directory let two concurrent pushes of the same version overwrite
        // each other's bytes — and let the loser, cleaning up after itself,
        // delete the winner's files. A rename is atomic: exactly one push
        // gets to own the directory, and only that one ever touches it again.
        $staging = sprintf('packages/%d/.incoming-%s', $feedId, bin2hex(random_bytes(6)));
        $this->makeDirectory($staging);

        try {
            $this->copyInto($sourcePath, $staging . '/' . $base . '.nupkg');

            // Kept verbatim rather than re-serialised: the flat container has
            // to serve back the exact bytes the author published.
            $this->write($staging . '/' . $metadata->idLower() . '.nuspec', $reader->nuspecXml());

            $icon   = $this->extractAsset($reader, $metadata->icon, $directory, 'icon', $staging);
            $readme = $this->extractAsset($reader, $metadata->readme, $directory, 'readme', $staging);

            $this->claim($staging, $directory);
        } catch (Throwable $e) {
            $this->discard($staging);

            throw $e;
        }

        return [
            'directory' => $directory,
            'nupkg'     => $directory . '/' . $base . '.nupkg',
            'nuspec'    => $directory . '/' . $metadata->idLower() . '.nuspec',
            'icon'      => $icon,
            'readme'    => $readme,
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
        string $writeDirectory,
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
            $reader->extractEntry($entry, $this->absolute($writeDirectory . '/' . basename($relative)), $this->maxAssetBytes);
        } catch (RuntimeException) {
            return null;
        }

        // The path to record is where the file will live once claim() has
        // moved the staging directory there, not where it is written now.
        return $relative;
    }

    /**
     * Moves a fully written staging directory to its final place.
     *
     * Publishing checks the database for the version before it gets here, so
     * a directory already standing at the destination is not a committed
     * version. It is either another push of this very version, still between
     * claiming the directory and committing its rows, or the leftover of one
     * that died there. Only age tells those apart: a push takes seconds, so
     * anything older than the grace period is cleared away; anything younger
     * is left alone and this push is the one that loses.
     *
     * @throws StorageConflictException when another push holds the destination
     */
    private function claim(string $staging, string $destination): void
    {
        $this->makeDirectory(dirname($destination));

        $target = $this->absolute($destination);

        if (is_dir($target) && (time() - (int) filemtime($target)) > self::ORPHAN_GRACE_SECONDS) {
            $this->discard($destination);
        }

        if (! @rename($this->absolute($staging), $target)) {
            throw new StorageConflictException(sprintf('"%s" is already being written by another push.', $destination));
        }
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
