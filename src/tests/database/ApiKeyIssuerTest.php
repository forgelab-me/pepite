<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\ApiKeyIssuer;
use App\Models\AuditLogModel;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Throwable;

/**
 * A key is a token plus an optional rule row, and a token without the rule it
 * was meant to have is an unrestricted key. What matters here is that the two
 * are created, changed and removed together, and that each leaves a trace.
 *
 * @internal
 */
final class ApiKeyIssuerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private User $user;
    private int $feedId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = model(UserModel::class);
        $users->save(new User(['username' => 'owner', 'email' => 'owner@pepite.test', 'password' => 'pepite-test-2026']));
        $this->user = $users->findById($users->getInsertID());

        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $this->feedId = (int) model(FeedModel::class)->getInsertID();
    }

    public function testIssuingARestrictedKeyStoresTheTokenAndItsRule(): void
    {
        $token = service('apiKeyIssuer')->issue($this->user, 'CI', ApiKeyIssuer::scopes(false), $this->rule());

        $this->seeInDatabase('auth_identities', ['id' => $token->id, 'type' => 'access_token']);
        $this->seeInDatabase('feed_api_key_rules', ['identity_id' => $token->id, 'feed_id' => $this->feedId]);
    }

    public function testAKeyWhoseRuleCannotBeStoredIsNotLeftBehindUnrestricted(): void
    {
        $this->assertThrows(fn () => service('apiKeyIssuer')->issue(
            $this->user,
            'CI',
            ApiKeyIssuer::scopes(false),
            $this->refusedRule(),
        ));

        $this->dontSeeInDatabase('auth_identities', ['type' => 'access_token']);
    }

    public function testAMintedKeyWhoseRuleCannotBeStoredIsNotLeftBehindEither(): void
    {
        $this->assertThrows(fn () => service('apiKeyIssuer')->mintExpiring($this->user, 'run', ['packages.push'], 15, $this->refusedRule()));

        $this->dontSeeInDatabase('auth_identities', ['type' => 'access_token']);
    }

    public function testRevokingRemovesTheTokenAndItsRuleTogether(): void
    {
        $issuer = service('apiKeyIssuer');
        $token  = $issuer->issue($this->user, 'CI', ApiKeyIssuer::scopes(false), $this->rule());

        $issuer->revoke((int) $token->id);

        $this->dontSeeInDatabase('auth_identities', ['id' => $token->id]);
        $this->dontSeeInDatabase('feed_api_key_rules', ['identity_id' => $token->id]);
    }

    public function testRevokeNeverTouchesALoginIdentity(): void
    {
        $login = db_connect()->table('auth_identities')
            ->where('user_id', $this->user->id)
            ->where('type', 'email_password')
            ->get()
            ->getRowArray();

        service('apiKeyIssuer')->revoke((int) $login['id']);

        $this->seeInDatabase('auth_identities', ['id' => $login['id']]);
    }

    public function testReconfigureReplacesScopesAndRule(): void
    {
        $issuer = service('apiKeyIssuer');
        $token  = $issuer->issue($this->user, 'CI', ApiKeyIssuer::scopes(false), $this->rule());

        $issuer->reconfigure((int) $token->id, ApiKeyIssuer::scopes(true), null);

        $this->dontSeeInDatabase('feed_api_key_rules', ['identity_id' => $token->id]);
        $this->assertSame(
            ApiKeyIssuer::scopes(true),
            unserialize((string) db_connect()->table('auth_identities')->where('id', $token->id)->get()->getRowArray()['extra']),
        );
    }

    public function testEveryChangeIsAudited(): void
    {
        $issuer = service('apiKeyIssuer');
        $token  = $issuer->issue($this->user, 'CI', ApiKeyIssuer::scopes(false), $this->rule());
        $issuer->reconfigure((int) $token->id, ApiKeyIssuer::scopes(true), null);
        $issuer->revoke((int) $token->id);

        $actions = array_column(model(AuditLogModel::class)->orderBy('id', 'ASC')->findAll(), 'action');

        $this->assertSame(['key.issue', 'key.update', 'key.revoke'], $actions);
    }

    public function testTheRuleIsStoredAsGiven(): void
    {
        $token = service('apiKeyIssuer')->issue($this->user, 'CI', [], $this->rule());

        $rules = model(FeedApiKeyRuleModel::class)->where('identity_id', $token->id)->findAll();

        $this->assertCount(1, $rules);
        $this->assertSame('Contoso.*', $rules[0]['id_pattern']);
        $this->assertSame(0, (int) $rules[0]['can_create_package']);
    }

    /**
     * @return array{feed_id: int, id_pattern: string, can_create_package: bool}
     */
    private function rule(): array
    {
        return ['feed_id' => $this->feedId, 'id_pattern' => 'Contoso.*', 'can_create_package' => false];
    }

    /**
     * A rule the table refuses: can_create_package is NOT NULL.
     *
     * @return array{feed_id: int, id_pattern: string|null, can_create_package: bool}
     */
    private function refusedRule(): array
    {
        return ['feed_id' => $this->feedId, 'id_pattern' => null, 'can_create_package' => null];
    }

    private function assertThrows(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the operation to fail.');
    }
}
