<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Restricts what one API key may push, per feed.
 *
 * A key with no row here is unrestricted — the nuget.org default. This model
 * only answers "what does this key allow", never "is this key valid": that is
 * Shield's job, already done by the time these rules are consulted.
 */
final class FeedApiKeyRuleModel extends Model
{
    protected $table         = 'feed_api_key_rules';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'identity_id',
        'feed_id',
        'id_pattern',
        'can_create_package',
        'created_at',
    ];

    /**
     * Every rule that applies to this key on this feed: rows scoped to the
     * feed specifically, plus rows with no feed restriction at all.
     *
     * @return list<array<string, mixed>>
     */
    public function forIdentityAndFeed(int $identityId, int $feedId): array
    {
        return $this->where('identity_id', $identityId)
            ->groupStart()
            ->where('feed_id', $feedId)
            ->orWhere('feed_id', null)
            ->groupEnd()
            ->findAll();
    }

    public function hasAnyRule(int $identityId): bool
    {
        return $this->where('identity_id', $identityId)->countAllResults() > 0;
    }
}
