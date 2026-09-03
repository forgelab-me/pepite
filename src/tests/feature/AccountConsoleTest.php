<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * account/* — self-service API keys and "my packages", the third-party
 * counterpart to the admin console: any logged-in user, no group required.
 *
 * @internal
 */
final class AccountConsoleTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function tearDown(): void
    {
        // actingAs() logs a real session in via Shield's Session
        // authenticator — DatabaseTestTrait's $refresh wipes the database
        // between tests, not the PHP session, so a dangling login here would
        // otherwise leak into whichever test class runs next in the same
        // process.
        auth('session')->getAuthenticator()->logout();

        parent::tearDown();
    }

    public function testAGuestIsRedirectedToLoginForAccountPages(): void
    {
        $this->call('get', 'account')->assertRedirectTo(site_url('login'));
        $this->call('get', 'account/keys')->assertRedirectTo(site_url('login'));
    }

    public function testAUserCanIssueASelfServiceKeyForAPublicFeed(): void
    {
        $user   = $this->user('dev@pepite.test');
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];

        $this->postWithCsrf($user, 'account/keys/create', 'account/keys', ['name' => 'CI', 'feed' => 'default'])
            ->assertOK();

        $rule = model(FeedApiKeyRuleModel::class)->where('feed_id', $feedId)->first();

        $this->assertNotNull($rule);
        $this->assertSame(1, (int) $rule['can_create_package']);
        $this->assertNull($rule['id_pattern']);
    }

    public function testAnIssuedKeyNeverCarriesTheReadScope(): void
    {
        $user = $this->user('dev@pepite.test');

        $this->postWithCsrf($user, 'account/keys/create', 'account/keys', ['name' => 'CI', 'feed' => 'default']);

        $identity = model(UserIdentityModel::class)->asArray()
            ->where('user_id', $user->id)
            ->where('type', 'access_token')
            ->first();

        $scopes = unserialize($identity['extra']);

        $this->assertNotContains('packages.read', $scopes, 'A self-service key must never carry packages.read — FeedRead authorizes every private feed on that scope alone.');
        $this->assertContains('packages.push', $scopes);
    }

    public function testAUserCannotSelfServiceAKeyForAPrivateFeed(): void
    {
        model(FeedModel::class)->insert(['slug' => 'secret', 'name' => 'Secret', 'visibility' => 'private', 'allow_new_packages' => true]);
        $user = $this->user('dev@pepite.test');

        $result = $this->postWithCsrf($user, 'account/keys/create', 'account/keys', ['name' => 'CI', 'feed' => 'secret']);

        $result->assertOK();
        $result->assertSee('Pick a feed');
        $this->assertSame(0, model(FeedApiKeyRuleModel::class)->countAllResults());
    }

    public function testAUserCannotSelfServiceAKeyForAFeedThatDisallowsNewPackages(): void
    {
        model(FeedModel::class)->insert(['slug' => 'curated', 'name' => 'Curated', 'visibility' => 'public', 'allow_new_packages' => false]);
        $user = $this->user('dev@pepite.test');

        $result = $this->postWithCsrf($user, 'account/keys/create', 'account/keys', ['name' => 'CI', 'feed' => 'curated']);

        $result->assertOK();
        $result->assertSee('Pick a feed');
        $this->assertSame(0, model(FeedApiKeyRuleModel::class)->countAllResults());
    }

    public function testAUserCanOnlyRevokeTheirOwnKey(): void
    {
        $userA = $this->user('a@pepite.test');
        $userB = $this->user('b@pepite.test');

        // Issued directly rather than through the create form: this test is
        // about the revoke boundary, not a second exercise of issuance, and
        // a single actingAs() per test keeps the CSRF token/session honest.
        $token = $userA->generateAccessToken('A key', ['packages.push']);

        // Not 'account/keys' as the form page: with no keys of B's own yet,
        // that page renders no csrf_field() at all (the empty-state branch
        // has no form on it) — any page that does render one works, the
        // token is session-wide, not tied to a specific action.
        $this->postWithCsrf($userB, 'account/keys/create', 'account/keys/' . $token->id . '/revoke', []);

        $this->assertNotNull(model(UserIdentityModel::class)->find($token->id), 'User B must not be able to revoke user A\'s key.');
    }

    public function testRevokingOwnKeyDoesNotTouchOtherIdentityTypes(): void
    {
        $user = $this->user('dev@pepite.test');
        $this->postWithCsrf($user, 'account/keys/create', 'account/keys', ['name' => 'CI', 'feed' => 'default']);
        $token = model(UserIdentityModel::class)->asArray()->where('user_id', $user->id)->where('type', 'access_token')->first();

        $login = model(UserIdentityModel::class)->asArray()->where('user_id', $user->id)->where('type', 'email_password')->first();

        $this->postWithCsrf($user, 'account/keys', 'account/keys/' . $token['id'] . '/revoke', []);

        $this->assertNull(model(UserIdentityModel::class)->find($token['id']));
        $this->assertNotNull(model(UserIdentityModel::class)->find($login['id']), 'Revoking the API key must not touch the login credential.');
    }

    public function testAccountKeysIndexOnlyShowsTheCurrentUsersKeys(): void
    {
        $userA = $this->user('a@pepite.test');
        $userB = $this->user('b@pepite.test');

        $this->postWithCsrf($userA, 'account/keys/create', 'account/keys', ['name' => 'A key', 'feed' => 'default']);
        $this->postWithCsrf($userB, 'account/keys/create', 'account/keys', ['name' => 'B key', 'feed' => 'default']);

        $result = $this->actingAs($userA)->get('account/keys');

        $result->assertSee('A key');
        $result->assertDontSee('B key');
    }

    public function testAccountPackagesListsOwnedPackagesAcrossFeeds(): void
    {
        $user   = $this->user('dev@pepite.test');
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];

        model(PackageModel::class)->insert(['feed_id' => $feedId, 'package_id' => 'Contoso.Widgets', 'package_id_lower' => 'contoso.widgets']);
        $packageId = (int) model(PackageModel::class)->getInsertID();
        model(PackageOwnerModel::class)->claim($packageId, (int) $user->id);

        $result = $this->actingAs($user)->get('account');

        $result->assertSee('Contoso.Widgets');
        $result->assertSee('Default feed');
    }

    public function testAnOwnerCanDelistAndRelistTheirOwnVersion(): void
    {
        $user      = $this->user('dev@pepite.test');
        $packageId = $this->ownedPackage($user, 'Contoso.Widgets');
        $versionId = $this->addVersion($packageId, '1.0.0');

        $this->postWithCsrf($user, 'account/packages/' . $packageId, 'account/packages/' . $packageId . '/versions/' . $versionId . '/unlist', [])
            ->assertRedirectTo(site_url('account/packages/' . $packageId));

        $this->assertSame(0, (int) model(PackageVersionModel::class)->find($versionId)['is_listed']);

        $this->postWithCsrf($user, 'account/packages/' . $packageId, 'account/packages/' . $packageId . '/versions/' . $versionId . '/relist', []);

        $this->assertSame(1, (int) model(PackageVersionModel::class)->find($versionId)['is_listed']);
    }

    public function testDelistingNeverDeletesTheVersion(): void
    {
        $user      = $this->user('dev@pepite.test');
        $packageId = $this->ownedPackage($user, 'Contoso.Widgets');
        $versionId = $this->addVersion($packageId, '1.0.0');

        $this->postWithCsrf($user, 'account/packages/' . $packageId, 'account/packages/' . $packageId . '/versions/' . $versionId . '/unlist', []);

        $this->assertNotNull(model(PackageVersionModel::class)->find($versionId));
        $this->assertNotNull(model(PackageModel::class)->find($packageId));
    }

    public function testAStrangerCannotViewAPackageTheyDoNotOwn(): void
    {
        $owner     = $this->user('owner@pepite.test');
        $stranger  = $this->user('stranger@pepite.test');
        $packageId = $this->ownedPackage($owner, 'Contoso.Widgets');

        $this->expectException(PageNotFoundException::class);

        $this->actingAs($stranger)->call('get', 'account/packages/' . $packageId);
    }

    public function testAStrangerCannotDelistAVersionTheyDoNotOwn(): void
    {
        $owner     = $this->user('owner@pepite.test');
        $stranger  = $this->user('stranger@pepite.test');
        $packageId = $this->ownedPackage($owner, 'Contoso.Widgets');
        $versionId = $this->addVersion($packageId, '1.0.0');

        try {
            // 'account' itself never renders a csrf_field() (no form on that
            // page) — 'account/keys/create' always does; the token is
            // session-wide, not tied to a specific action.
            $this->postWithCsrf($stranger, 'account/keys/create', 'account/packages/' . $packageId . '/versions/' . $versionId . '/unlist', []);
            $this->fail('Expected a PageNotFoundException.');
        } catch (PageNotFoundException) {
            // Expected — postWithCsrf() already issued the request by the
            // time the exception surfaces, so the assertion below still
            // runs against real post-request state.
        }

        $this->assertSame(1, (int) model(PackageVersionModel::class)->find($versionId)['is_listed'], 'A non-owner must not be able to delist someone else\'s version.');
    }

    private function ownedPackage(User $user, string $packageId): int
    {
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];

        model(PackageModel::class)->insert([
            'feed_id'          => $feedId,
            'package_id'       => $packageId,
            'package_id_lower' => strtolower($packageId),
        ]);
        $id = (int) model(PackageModel::class)->getInsertID();

        model(PackageOwnerModel::class)->claim($id, (int) $user->id);

        return $id;
    }

    private function addVersion(int $packageId, string $version): int
    {
        model(PackageVersionModel::class)->insert([
            'package_id'               => $packageId,
            'version_original'         => $version,
            'version_normalized'       => $version,
            'version_normalized_lower' => $version,
            'version_sort_key'         => $version,
            'is_listed'                => true,
            'nupkg_path'               => 'packages/x/x/x.nupkg',
            'nuspec_path'              => 'packages/x/x/x.nuspec',
            'sha512_base64'            => base64_encode(hash('sha512', 'fixture', true)),
        ]);

        return (int) model(PackageVersionModel::class)->getInsertID();
    }

    private function user(string $email): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['username' => strstr($email, '@', true), 'email' => $email, 'password' => 'pepite-test-2026']));
        $user = $users->findById($users->getInsertID());
        $user->activate();

        return $user;
    }

    /**
     * @param array<string, string> $data
     */
    private function postWithCsrf(User $user, string $formPage, string $action, array $data)
    {
        $this->actingAs($user);

        $page  = $this->get($formPage)->response()->getBody();
        $token = (preg_match('/name="csrf_test_name"\s+value="([^"]+)"/', (string) $page, $m) === 1) ? $m[1] : '';

        return $this->post($action, $data + ['csrf_test_name' => $token]);
    }
}
