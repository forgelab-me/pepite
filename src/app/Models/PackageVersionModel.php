<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Version\NuGetVersion;
use CodeIgniter\Model;

final class PackageVersionModel extends Model
{
    public const SEMVER_LEGACY = 0;
    public const SEMVER_2      = 2;

    protected $table         = 'package_versions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'package_id',
        'version_original',
        'version_normalized',
        'version_normalized_lower',
        'version_sort_key',
        'is_prerelease',
        'is_listed',
        'semver_level',
        'title',
        'description',
        'summary',
        'release_notes',
        'authors',
        'owners',
        'tags',
        'copyright',
        'language',
        'project_url',
        'icon_url',
        'license_url',
        'license_type',
        'license_value',
        'require_license_acceptance',
        'development_dependency',
        'min_client_version',
        'repository_type',
        'repository_url',
        'repository_branch',
        'repository_commit',
        'package_types',
        'nupkg_path',
        'snupkg_path',
        'nuspec_path',
        'icon_path',
        'readme_path',
        'size_bytes',
        'sha512_base64',
        'downloads',
        'published_at',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function findVersion(int $packageId, NuGetVersion $version): ?array
    {
        return $this->where('package_id', $packageId)
            ->where('version_normalized_lower', $version->normalizedLower())
            ->first();
    }

    public function versionExists(int $packageId, NuGetVersion $version): bool
    {
        return $this->where('package_id', $packageId)
            ->where('version_normalized_lower', $version->normalizedLower())
            ->countAllResults() > 0;
    }

    /**
     * Every version of a package, oldest first.
     *
     * Ordering is done by the database on the sort key, never in PHP: that is
     * what makes paging and "latest version" cheap.
     *
     * @return list<array<string, mixed>>
     */
    public function forPackage(
        int $packageId,
        bool $includeUnlisted = true,
        bool $includePrerelease = true,
        bool $semVer2 = true,
    ): array {
        $builder = $this->where('package_id', $packageId);

        if (! $includeUnlisted) {
            $builder->where('is_listed', true);
        }

        if (! $includePrerelease) {
            $builder->where('is_prerelease', false);
        }

        // A client that has not announced semVerLevel=2.0.0 cannot parse these
        // versions, so it must not be told they exist.
        if (! $semVer2) {
            $builder->where('semver_level', self::SEMVER_LEGACY);
        }

        return $builder->orderBy('version_sort_key', 'ASC')->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForPackage(
        int $packageId,
        bool $includePrerelease = false,
        bool $semVer2 = true,
    ): ?array {
        $builder = $this->where('package_id', $packageId)->where('is_listed', true);

        if (! $includePrerelease) {
            $builder->where('is_prerelease', false);
        }

        if (! $semVer2) {
            $builder->where('semver_level', self::SEMVER_LEGACY);
        }

        return $builder->orderBy('version_sort_key', 'DESC')->first();
    }

    /**
     * Counts one download of the archive itself — never the nuspec, icon or
     * readme, which a client fetches incidentally and which nuget.org does
     * not count either.
     */
    public function recordDownload(int $versionId, int $packageId): void
    {
        $this->builder()->where('id', $versionId)->increment('downloads');
        model(PackageModel::class)->builder()->where('id', $packageId)->increment('total_downloads');
    }
}
