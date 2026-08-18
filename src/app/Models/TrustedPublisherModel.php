<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * The trust policy behind Trusted Publishing: which GitHub workflow may
 * exchange its OIDC identity for a push credential on a given feed.
 *
 * Deciding whether a verified identity matches one of these rows is
 * forgelab-me/ci4-trusted-publishing's PublisherMatcher, not this class —
 * this only owns the rows themselves. Its column names (provider,
 * repository, repository_owner_id, environment) already match that
 * package's defaults, so App\Controllers\Api\PublishToken passes forFeed()'s
 * result straight to PublisherMatcher::match() with no column remapping.
 */
final class TrustedPublisherModel extends Model
{
    protected $table         = 'trusted_publishers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'feed_id',
        'user_id',
        'provider',
        'repository',
        'repository_owner_id',
        'environment',
        'id_pattern',
        'can_create_package',
        'last_used_at',
        'created_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forFeed(int $feedId): array
    {
        return $this->where('feed_id', $feedId)->orderBy('id', 'DESC')->findAll();
    }

    public function touch(int $id): void
    {
        $this->builder()->where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }
}
