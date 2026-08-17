<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class FeedModel extends Model
{
    protected $table         = 'feeds';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'slug',
        'name',
        'description',
        'visibility',
        'allow_new_packages',
        'allowed_package_types',
        'owner_user_id',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', strtolower($slug))->first();
    }

    /**
     * The package types this feed accepts. An empty list means any.
     *
     * @param array<string, mixed> $feed
     *
     * @return list<string>
     */
    public static function allowedPackageTypes(array $feed): array
    {
        $raw = $feed['allowed_package_types'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }

    /**
     * @param array<string, mixed> $feed
     */
    public static function isPublic(array $feed): bool
    {
        return ($feed['visibility'] ?? 'public') === 'public';
    }
}
