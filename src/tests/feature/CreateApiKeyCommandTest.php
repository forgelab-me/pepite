<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `spark pepite:key` — issuing and restricting API keys from the shell.
 *
 * @internal
 */
final class CreateApiKeyCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('dev@pepite.test');
    }

    public function testIssuesAFullAccessKeyByDefault(): void
    {
        command('pepite:key -e dev@pepite.test');

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();
        $scopes   = unserialize($identity->extra);

        $this->assertSame(['packages.read', 'packages.push', 'packages.unlist'], $scopes);
    }

    /**
     * Regression: --read-only used to be lost. CI4's own CLI parser records a
     * valueless flag as null in $params, and null is indistinguishable from
     * "absent" under `??` — so the key silently came out with full access.
     */
    public function testReadOnlyFlagRestrictsScopes(): void
    {
        command('pepite:key -e dev@pepite.test --read-only');

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();
        $scopes   = unserialize($identity->extra);

        $this->assertSame(['packages.read'], $scopes);
    }

    public function testFeedAndPatternAttachARestrictionRule(): void
    {
        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);

        command('pepite:key -e dev@pepite.test --feed contoso --pattern "Contoso.*" --no-create');

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();
        $rule     = model(FeedApiKeyRuleModel::class)->where('identity_id', $identity->id)->first();

        $feed = model(FeedModel::class)->findBySlug('contoso');

        $this->assertNotNull($rule);
        $this->assertSame((int) $feed['id'], (int) $rule['feed_id']);
        $this->assertSame('Contoso.*', $rule['id_pattern']);
        $this->assertSame(0, (int) $rule['can_create_package']);
    }

    public function testNoRuleIsAttachedWithoutRestrictionOptions(): void
    {
        command('pepite:key -e dev@pepite.test');

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();

        $this->assertFalse(model(FeedApiKeyRuleModel::class)->hasAnyRule((int) $identity->id));
    }

    /**
     * CLI::error() writes straight to STDERR rather than the buffer
     * command() captures, so the refusal is checked through its effect —
     * no key created — rather than by scraping output text.
     */
    public function testAnUnknownFeedIsRejected(): void
    {
        command('pepite:key -e dev@pepite.test --feed nope');

        $this->assertSame(0, model(UserIdentityModel::class)->where('type', 'access_token')->countAllResults());
    }

    private function createUser(string $email): User
    {
        $users = model(UserModel::class);

        $users->save(new User([
            'username' => explode('@', $email)[0],
            'email'    => $email,
            'password' => 'pepite-test-2026',
        ]));

        return $users->findById($users->getInsertID());
    }
}
