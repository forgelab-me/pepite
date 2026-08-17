<?php

declare(strict_types=1);

namespace App\Controllers\V3;

use App\Libraries\V3\RegistrationBuilder;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * RegistrationsBaseUrl — package metadata, and the source of dependency
 * resolution.
 *
 * Served under two base paths that differ only in whether SemVer 2 versions
 * are visible. That is how the protocol filters them: nuget.org publishes
 * registration5-semver1 and registration5-gz-semver2 and lets the client pick
 * from the service index, rather than accepting a query parameter.
 */
final class Registration extends BaseV3Controller
{
    public function index(string $slug, string $id): ResponseInterface
    {
        return $this->buildIndex($slug, $id, semVer2: false);
    }

    public function indexSemVer2(string $slug, string $id): ResponseInterface
    {
        return $this->buildIndex($slug, $id, semVer2: true);
    }

    public function leaf(string $slug, string $id, string $file): ResponseInterface
    {
        return $this->buildLeaf($slug, $id, $file, semVer2: false);
    }

    public function leafSemVer2(string $slug, string $id, string $file): ResponseInterface
    {
        return $this->buildLeaf($slug, $id, $file, semVer2: true);
    }

    private function buildIndex(string $slug, string $id, bool $semVer2): ResponseInterface
    {
        $feed = $this->feed($slug);

        if ($feed === null) {
            return $this->unknownFeed($slug);
        }
        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return $this->notFound(sprintf('No package named "%s" in feed "%s".', $id, $slug));
        }

        $versions = model(PackageVersionModel::class)->forPackage(
            (int) $package['id'],
            includeUnlisted: true,
            includePrerelease: true,
            semVer2: $semVer2,
        );

        if ($versions === []) {
            return $this->notFound(sprintf('No visible version of "%s".', $id));
        }

        $builder = new RegistrationBuilder($this->urls($slug));

        return $this->json($builder->index(
            $package,
            $versions,
            $this->dependenciesByVersion($versions),
            $semVer2,
        ));
    }

    private function buildLeaf(string $slug, string $id, string $file, bool $semVer2): ResponseInterface
    {
        if (! str_ends_with(strtolower($file), '.json')) {
            return $this->notFound('A registration leaf is addressed as {version}.json.');
        }

        $version = substr($file, 0, -5);
        $feed    = $this->feed($slug);

        if ($feed === null) {
            return $this->unknownFeed($slug);
        }
        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return $this->notFound(sprintf('No package named "%s" in feed "%s".', $id, $slug));
        }

        $row = model(PackageVersionModel::class)
            ->where('package_id', (int) $package['id'])
            ->where('version_normalized_lower', strtolower($version))
            ->first();

        if ($row === null) {
            return $this->notFound(sprintf('No version "%s" of "%s".', $version, $id));
        }

        if (! $semVer2 && (int) $row['semver_level'] !== PackageVersionModel::SEMVER_LEGACY) {
            return $this->notFound('This version requires a SemVer 2.0.0 aware client.');
        }

        $builder = new RegistrationBuilder($this->urls($slug));

        return $this->json($builder->leaf($package, $row, $semVer2));
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function dependenciesByVersion(array $versions): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $versions);

        // One query for the whole registration rather than one per version: a
        // package with fifty versions would otherwise mean fifty round trips.
        $rows = model(PackageDependencyModel::class)
            ->whereIn('package_version_id', $ids)
            ->orderBy('id', 'ASC')
            ->findAll();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['package_version_id']][] = $row;
        }

        return $grouped;
    }
}
