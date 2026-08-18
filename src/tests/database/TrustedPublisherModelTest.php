<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Models\FeedModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Forgelabme\TrustedPublishing\Identity;
use Forgelabme\TrustedPublishing\PublisherMatcher;

/**
 * The comparison rules themselves (owner id over name, environment required
 * when set, case-insensitive repository) are forgelab-me/ci4-trusted-
 * publishing's own PublisherMatcherTest to prove, not this suite's. What
 * belongs here is the wiring: forFeed() has to hand PublisherMatcher rows
 * shaped the way it expects, with no column remapping, and touch() has to
 * work. PublishTokenTest covers the full HTTP path on top of this.
 *
 * @internal
 */
final class TrustedPublisherModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private int $feedId;

    protected function setUp(): void
    {
        parent::setUp();

        // Not 'default': the migration itself seeds that slug ("Created on
        // install."), so reusing it here would collide with it.
        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $this->feedId = (int) model(FeedModel::class)->getInsertID();
    }

    public function testForFeedRowsMatchThroughPublisherMatcherWithNoColumnRemapping(): void
    {
        $this->trust(['repository' => 'forgelab-me/pepite', 'repository_owner_id' => '10387667']);

        $identity = new Identity('github', 'forgelab-me/pepite', '10387667');
        $rows     = model(TrustedPublisherModel::class)->forFeed($this->feedId);

        $this->assertNotNull((new PublisherMatcher())->match($rows, $identity));
    }

    public function testForFeedIsScopedToTheFeed(): void
    {
        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $otherFeedId = (int) model(FeedModel::class)->getInsertID();

        $this->trust(['repository' => 'forgelab-me/pepite', 'repository_owner_id' => '10387667'], $otherFeedId);

        $this->assertSame([], model(TrustedPublisherModel::class)->forFeed($this->feedId));
        $this->assertCount(1, model(TrustedPublisherModel::class)->forFeed($otherFeedId));
    }

    public function testTouchStampsLastUsedAt(): void
    {
        $this->trust(['repository' => 'forgelab-me/pepite', 'repository_owner_id' => '10387667']);
        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];

        $this->assertNull($publisher['last_used_at']);

        model(TrustedPublisherModel::class)->touch((int) $publisher['id']);

        $reloaded = model(TrustedPublisherModel::class)->find($publisher['id']);
        $this->assertNotNull($reloaded['last_used_at']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function trust(array $overrides, ?int $feedId = null): void
    {
        model(TrustedPublisherModel::class)->insert($overrides + [
            'feed_id'            => $feedId ?? $this->feedId,
            'user_id'            => 1,
            'provider'           => 'github',
            'environment'        => null,
            'id_pattern'         => null,
            'can_create_package' => false,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }
}
