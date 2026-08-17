<?php

declare(strict_types=1);

namespace App\Controllers\V3;

use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PackageBaseAddress/3.0.0 — the flat container.
 *
 * The minimum a client needs to consume a package whose identifier it already
 * knows: the list of versions, and the archive itself.
 *
 * Unlike search, this endpoint hides nothing. Delisted versions stay listed
 * here and stay downloadable, because delisting removes a version from
 * discovery, not from the record — anything already depending on it must keep
 * restoring. SemVer 2 versions are likewise always present, matching what
 * nuget.org serves.
 */
final class FlatContainer extends BaseV3Controller
{
    public function versions(string $slug, string $id): ResponseInterface
    {
        $version = $this->packageVersions($slug, $id);

        if ($version === null) {
            return $this->notFound(sprintf('No package named "%s" in feed "%s".', $id, $slug));
        }

        return $this->json([
            'versions' => array_map(
                static fn (array $row): string => (string) $row['version_normalized_lower'],
                $version,
            ),
        ]);
    }

    /**
     * Serves one file of one version: the archive, its manifest, its icon or
     * its readme. Routed as a single method because they share every lookup.
     */
    public function file(string $slug, string $id, string $version, string $file): ResponseInterface
    {
        $row = $this->version($slug, $id, $version);

        if ($row === null) {
            return $this->notFound(sprintf('No version "%s" of "%s".', $version, $id));
        }

        $idLower      = strtolower($id);
        $versionLower = strtolower($version);
        $requested    = strtolower($file);

        return match (true) {
            $requested === sprintf('%s.%s.nupkg', $idLower, $versionLower) => $this->streamArchive($row, $file),
            $requested === $idLower . '.nuspec'                            => $this->inline((string) $row['nuspec_path'], 'text/xml'),
            $requested === 'icon'                                          => $this->asset($row['icon_path'], 'image/png'),
            $requested === 'readme'                                        => $this->asset($row['readme_path'], 'text/markdown'),
            default                                                        => $this->notFound(sprintf('No file named "%s".', $file)),
        };
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function packageVersions(string $slug, string $id): ?array
    {
        $feed = $this->feed($slug);

        if ($feed === null) {
            return null;
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return null;
        }

        $versions = model(PackageVersionModel::class)->forPackage((int) $package['id']);

        return $versions === [] ? null : $versions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function version(string $slug, string $id, string $version): ?array
    {
        $feed = $this->feed($slug);

        if ($feed === null) {
            return null;
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return null;
        }

        return model(PackageVersionModel::class)
            ->where('package_id', (int) $package['id'])
            ->where('version_normalized_lower', strtolower($version))
            ->first();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function streamArchive(array $row, string $fileName): ResponseInterface
    {
        $response = $this->stream((string) $row['nupkg_path'], 'application/octet-stream', $fileName);

        // Only counted once the file is confirmed on disk — stream() has
        // already turned a missing blob into a 404 by this point.
        if ($response->getStatusCode() !== 404) {
            model(PackageVersionModel::class)->recordDownload((int) $row['id'], (int) $row['package_id']);
        }

        return $response;
    }

    private function asset(?string $path, string $fallbackType): ResponseInterface
    {
        if ($path === null) {
            return $this->notFound('This version carries no such asset.');
        }

        return $this->inline($path, $this->mimeFor($path, $fallbackType));
    }

    /**
     * Streams from storage without loading the file into memory: a package can
     * be tens of megabytes and memory_limit is rarely generous on shared
     * hosting.
     */
    private function stream(string $relativePath, string $contentType, string $fileName): ResponseInterface
    {
        $storage  = service('packageStorage');
        $absolute = $storage->absolute($relativePath);

        if (! is_file($absolute)) {
            return $this->notFound('The stored package is missing.');
        }

        return $this->response->download($absolute, null)
            ->setFileName($fileName)
            ->setContentType($contentType);
    }

    private function inline(string $relativePath, string $contentType): ResponseInterface
    {
        $storage  = service('packageStorage');
        $absolute = $storage->absolute($relativePath);

        if (! is_file($absolute)) {
            return $this->notFound('The stored file is missing.');
        }

        return $this->response
            ->setContentType($contentType)
            ->setBody((string) file_get_contents($absolute));
    }

    private function mimeFor(string $path, string $fallback): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'svg'         => 'image/svg+xml',
            'md'          => 'text/markdown',
            'txt'         => 'text/plain',
            default       => $fallback,
        };
    }
}
