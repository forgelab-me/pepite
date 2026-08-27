<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class PackageDependencyModel extends Model
{
    protected $table         = 'package_dependencies';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'package_version_id',
        'target_framework',
        'dependency_id',
        'version_range',
        'include_assets',
        'exclude_assets',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forVersion(int $versionId): array
    {
        return $this->where('package_version_id', $versionId)->orderBy('id', 'ASC')->findAll();
    }

    /**
     * Other packages in the same feed that declare a dependency on this one
     * — nuget.org's "Used By". Grouped down to one row per package: a
     * dependent with the same range in three framework groups still only
     * appears once.
     *
     * dependency_id is copied from whatever the dependent package's own
     * nuspec spelled it as, never folded at write time — the comparison is
     * case-insensitive for the same reason identity everywhere else here is.
     *
     * @return list<array{package_id: string, package_id_lower: string, total_downloads: int}>
     */
    public function usedBy(int $feedId, string $dependencyIdLower, int $limit = 20): array
    {
        $dependencies = $this->db->prefixTable('package_dependencies');
        $versions     = $this->db->prefixTable('package_versions');
        $packages     = $this->db->prefixTable('packages');

        return $this->db->table($dependencies . ' d')
            ->select('p.package_id, p.package_id_lower, p.total_downloads')
            ->join($versions . ' v', 'v.id = d.package_version_id')
            ->join($packages . ' p', 'p.id = v.package_id')
            ->where('p.feed_id', $feedId)
            ->where('LOWER(d.dependency_id)', $dependencyIdLower)
            ->groupBy('p.id')
            ->orderBy('p.total_downloads', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }
}
