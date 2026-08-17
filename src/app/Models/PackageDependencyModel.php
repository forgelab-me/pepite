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
}
