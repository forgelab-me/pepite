<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Append-only: nothing in the app updates or deletes a row (Model's own
 * update() and delete() are simply never called). Written through App\Libraries\Audit, never directly.
 */
final class AuditLogModel extends Model
{
    protected $table         = 'audit_log';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'created_at',
        'actor_user_id',
        'actor_label',
        'via',
        'action',
        'target',
        'detail',
        'ip',
    ];

    /**
     * Newest first. $peek reads that many rows past the page, only to tell
     * whether a next page exists.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $page, int $perPage, int $peek = 0): array
    {
        return $this->orderBy('id', 'DESC')
            ->findAll($perPage + $peek, max(0, $page - 1) * $perPage);
    }
}
