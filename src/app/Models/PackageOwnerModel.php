<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

final class PackageOwnerModel extends Model
{
    protected $table         = 'package_owners';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'package_id',
        'user_id',
        'role',
        'created_at',
    ];

    public function owns(int $packageId, int $userId): bool
    {
        return $this->where('package_id', $packageId)->where('user_id', $userId)->countAllResults() > 0;
    }

    /**
     * Records ownership if it is not already recorded.
     *
     * First push claims the identifier. Retrofitting this rule onto a
     * populated feed means guessing who owns what, so it applies from the very
     * first package.
     */
    public function claim(int $packageId, int $userId, string $role = 'owner'): void
    {
        if ($this->owns($packageId, $userId)) {
            return;
        }

        $this->insert([
            'package_id' => $packageId,
            'user_id'    => $userId,
            'role'       => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<int>
     */
    public function userIdsFor(int $packageId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->where('package_id', $packageId)->findAll(),
        );
    }
}
