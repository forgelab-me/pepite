<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filters\NuGetApiKey;
use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * The admin console: feeds and API keys, the only way to manage either before
 * the CLI-only lot 5 tooling had a UI.
 *
 * Every POST goes through postWithCsrf(), which fetches a real token from the
 * form page first — FeatureTestTrait does not inject one, and the group
 * carries the csrf filter for real, as testFeedCreationRequiresCsrf checks.
 *
 * @internal
 */
final class AdminConsoleTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private User $admin;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $users = model(UserModel::class);
        $users->save(new User(['username' => 'admin', 'email' => 'admin@pepite.test', 'password' => 'pepite-test-2026']));
        $this->admin = $users->findById($users->getInsertID());
        $this->admin->addGroup('admin');

        $this->storageRoot = sys_get_temp_dir() . '/pepite-admin-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
        Services::resetSingle('feedResolver');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    public function testAGuestIsRedirectedToLogin(): void
    {
        $this->call('get', 'admin/feeds')->assertRedirectTo(route_to('login'));
    }

    public function testAnAdminCanCreateAFeed(): void
    {
        $result = $this->postWithCsrf('admin/feeds/create', 'admin/feeds', [
            'slug'            => 'contoso',
            'name'            => 'Contoso',
            'private'         => '1',
            'no_new_packages' => '1',
            'package_types'   => 'ConsoleApp',
        ]);

        $result->assertRedirect();

        $feed = model(FeedModel::class)->findBySlug('contoso');
        $this->assertNotNull($feed);
        $this->assertSame('private', $feed['visibility']);
        $this->assertSame(0, (int) $feed['allow_new_packages']);
        $this->assertSame(['ConsoleApp'], json_decode($feed['allowed_package_types'], true));
    }

    public function testADuplicateSlugIsRejected(): void
    {
        model(FeedModel::class)->insert(['slug' => 'dup', 'name' => 'Dup']);

        $this->postWithCsrf('admin/feeds/create', 'admin/feeds', ['slug' => 'dup', 'name' => 'Again']);

        $this->assertSame(1, model(FeedModel::class)->where('slug', 'dup')->countAllResults());
    }

    /**
     * Proves the group's csrf filter is doing real work, not merely declared.
     */
    public function testFeedCreationRequiresCsrf(): void
    {
        $this->expectException(SecurityException::class);

        $this->actingAs($this->admin)->post('admin/feeds', ['slug' => 'nocsrf', 'name' => 'No CSRF']);
    }

    public function testAnAdminCanIssueAndSeeAnApiKey(): void
    {
        $result = $this->postWithCsrf('admin/keys/create', 'admin/keys', [
            'email'     => 'admin@pepite.test',
            'name'      => 'test key',
            'read_only' => '1',
        ]);

        $result->assertOK();

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();
        $this->assertSame('test key', $identity->name);
        $this->assertSame(['packages.read'], unserialize($identity->extra));
    }

    public function testIssuingAKeyWithAFeedAndPatternAttachesARule(): void
    {
        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);

        $this->postWithCsrf('admin/keys/create', 'admin/keys', [
            'email'     => 'admin@pepite.test',
            'feed'      => 'contoso',
            'pattern'   => 'Contoso.*',
            'no_create' => '1',
        ]);

        $identity = model(UserIdentityModel::class)->where('type', 'access_token')->first();
        $rule     = model(FeedApiKeyRuleModel::class)->where('identity_id', $identity->id)->first();

        $this->assertNotNull($rule);
        $this->assertSame('Contoso.*', $rule['id_pattern']);
        $this->assertSame(0, (int) $rule['can_create_package']);
    }

    public function testIssuingAKeyForAnUnknownEmailFails(): void
    {
        $this->postWithCsrf('admin/keys/create', 'admin/keys', ['email' => 'nope@pepite.test']);

        $this->assertSame(0, model(UserIdentityModel::class)->where('type', 'access_token')->countAllResults());
    }

    public function testRevokingAKeyDeletesItAndItsRules(): void
    {
        $token = $this->admin->generateAccessToken('to revoke', ['packages.read']);
        model(FeedApiKeyRuleModel::class)->insert([
            'identity_id'        => (int) $token->id,
            'feed_id'            => null,
            'id_pattern'         => null,
            'can_create_package' => true,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->postWithCsrf('admin/keys', 'admin/keys/' . $token->id . '/revoke', []);

        $this->assertSame(0, model(UserIdentityModel::class)->where('id', $token->id)->countAllResults());
        $this->assertFalse(model(FeedApiKeyRuleModel::class)->hasAnyRule((int) $token->id));
    }

    // ------------------------------------------------------------ feed edit

    public function testAnAdminCanEditAFeed(): void
    {
        $feedId = $this->createFeed('contoso', 'Contoso');

        $result = $this->postWithCsrf('admin/feeds/' . $feedId . '/edit', 'admin/feeds/' . $feedId, [
            'name'    => 'Contoso Renamed',
            'private' => '1',
        ]);

        $result->assertRedirect();

        $feed = model(FeedModel::class)->find($feedId);
        $this->assertSame('Contoso Renamed', $feed['name']);
        $this->assertSame('private', $feed['visibility']);
    }

    public function testEditingAnUnknownFeedIs404(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->actingAs($this->admin)->call('get', 'admin/feeds/999999/edit');
    }

    /**
     * Deleting a feed cascades to its packages in the database (FK CASCADE,
     * lot 2) and has to clean up the blobs the database cannot reach.
     */
    public function testDeletingAFeedRemovesItsPackagesAndBlobs(): void
    {
        $feedId  = $this->createFeed('contoso', 'Contoso');
        $publish = $this->publish($feedId, 'Pepite.Fixtures.Simple.1.0.0.nupkg');

        $stored = model(PackageVersionModel::class)->first();
        $this->assertTrue(service('packageStorage')->exists($stored['nupkg_path']));

        $this->postWithCsrf('admin/feeds', 'admin/feeds/' . $feedId . '/delete', [])->assertRedirect();

        $this->assertNull(model(FeedModel::class)->find($feedId));
        $this->assertSame(0, model(PackageModel::class)->where('feed_id', $feedId)->countAllResults());
        $this->assertFalse(service('packageStorage')->exists($stored['nupkg_path']));
    }

    // --------------------------------------------------------- admin packages

    public function testAnAdminCanBrowsePackagesInAFeed(): void
    {
        $feedId = $this->createFeed('contoso', 'Contoso');
        $this->publish($feedId, 'Pepite.Fixtures.Simple.1.0.0.nupkg');

        $result = $this->actingAs($this->admin)->call('get', 'admin/feeds/' . $feedId . '/packages');

        $result->assertOK();
        $result->assertSee('Pepite.Fixtures.Simple');
    }

    public function testAnAdminCanUnlistAndRelistAVersion(): void
    {
        $feedId  = $this->createFeed('contoso', 'Contoso');
        $publish = $this->publish($feedId, 'Pepite.Fixtures.Simple.1.0.0.nupkg');
        $base    = 'admin/feeds/' . $feedId . '/packages/' . $publish->packageRowId
            . '/versions/' . $publish->versionRowId;

        $this->postWithCsrf('admin/feeds/' . $feedId . '/packages/' . $publish->packageRowId, $base . '/unlist', [])
            ->assertRedirect();

        $version = model(PackageVersionModel::class)->find($publish->versionRowId);
        $this->assertSame(0, (int) $version['is_listed']);

        $this->postWithCsrf('admin/feeds/' . $feedId . '/packages/' . $publish->packageRowId, $base . '/relist', [])
            ->assertRedirect();

        $version = model(PackageVersionModel::class)->find($publish->versionRowId);
        $this->assertSame(1, (int) $version['is_listed']);
    }

    // ---------------------------------------------------------------- key edit

    public function testAnAdminCanEditAKeysScopeAndRestrictions(): void
    {
        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $token = $this->admin->generateAccessToken('key', ['packages.read']);

        $this->postWithCsrf('admin/keys/' . $token->id . '/edit', 'admin/keys/' . $token->id, [
            'feed'    => 'contoso',
            'pattern' => 'Contoso.*',
        ])->assertRedirect();

        $identity = model(UserIdentityModel::class)->find($token->id);
        $this->assertSame(
            ['packages.read', NuGetApiKey::SCOPE_PUSH, NuGetApiKey::SCOPE_UNLIST],
            unserialize($identity->extra),
        );

        $rule = model(FeedApiKeyRuleModel::class)->where('identity_id', $token->id)->first();
        $this->assertSame('Contoso.*', $rule['id_pattern']);
    }

    public function testEditingAKeyCanRemoveItsRestriction(): void
    {
        $token = $this->admin->generateAccessToken('key', ['packages.read']);
        model(FeedApiKeyRuleModel::class)->insert([
            'identity_id'        => (int) $token->id,
            'feed_id'            => null,
            'id_pattern'         => 'Contoso.*',
            'can_create_package' => true,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->postWithCsrf('admin/keys/' . $token->id . '/edit', 'admin/keys/' . $token->id, [
            'read_only' => '1',
        ])->assertRedirect();

        $this->assertFalse(model(FeedApiKeyRuleModel::class)->hasAnyRule((int) $token->id));

        $identity = model(UserIdentityModel::class)->find($token->id);
        $this->assertSame(['packages.read'], unserialize($identity->extra));
    }

    // ---------------------------------------------------------------- admins

    public function testTheAdminsPageListsAccounts(): void
    {
        $result = $this->actingAs($this->admin)->call('get', 'admin/users');

        $result->assertOK();
        $result->assertSee('admin@pepite.test');
    }

    public function testAnAdminCanCreateAnotherAdmin(): void
    {
        $result = $this->postWithCsrf('admin/users/create', 'admin/users', [
            'email'            => 'second@pepite.test',
            'password'         => 'a-genuinely-long-passphrase',
            'password_confirm' => 'a-genuinely-long-passphrase',
        ]);

        $result->assertRedirect();

        $created = model(UserModel::class)->findByCredentials(['email' => 'second@pepite.test']);
        $this->assertNotNull($created);
        $this->assertTrue($created->inGroup('admin'));
        $this->assertTrue($created->active);
    }

    public function testCreatingAnAdminWithAShortPasswordFails(): void
    {
        $this->postWithCsrf('admin/users/create', 'admin/users', [
            'email'    => 'second@pepite.test',
            'password' => 'short',
        ]);

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'second@pepite.test']));
    }

    public function testCreatingAnAdminWithADuplicateEmailFails(): void
    {
        $this->postWithCsrf('admin/users/create', 'admin/users', [
            'email'            => 'admin@pepite.test',
            'username'         => 'different-username',
            'password'         => 'a-genuinely-long-passphrase',
            'password_confirm' => 'a-genuinely-long-passphrase',
        ]);

        $this->assertSame(0, model(UserModel::class)->where('username', 'different-username')->countAllResults());
    }

    public function testAnAdminCanRemoveAnotherAdminsAccess(): void
    {
        $second = $this->createAdmin('second@pepite.test');

        $this->postWithCsrf('admin/users', 'admin/users/' . $second->id . '/delete', [])->assertRedirect();

        $second = model(UserModel::class)->findById($second->id);
        $this->assertFalse($second->inGroup('admin'));
    }

    public function testAnAdminCannotRemoveTheirOwnAccess(): void
    {
        $this->createAdmin('second@pepite.test');

        $this->postWithCsrf('admin/users', 'admin/users/' . $this->admin->id . '/delete', []);

        $stillAdmin = model(UserModel::class)->findById($this->admin->id);
        $this->assertTrue($stillAdmin->inGroup('admin'));
    }

    private function createAdmin(string $email): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['username' => strstr($email, '@', true), 'email' => $email, 'password' => 'a-genuinely-long-passphrase']));
        $user = $users->findById($users->getInsertID());
        $user->addGroup('admin');
        $user->activate();

        return $user;
    }

    private function createFeed(string $slug, string $name): int
    {
        model(FeedModel::class)->insert(['slug' => $slug, 'name' => $name]);

        return (int) model(FeedModel::class)->getInsertID();
    }

    private function publish(int $feedId, string $fixture)
    {
        $feed = model(FeedModel::class)->find($feedId);

        $publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            service('packageStorage'),
            db_connect(),
        );

        return $publisher->publish(Fixtures::package($fixture), $feed['slug'], (int) $this->admin->id);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path . '/*') as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    /**
     * @param array<string, string> $data
     */
    private function postWithCsrf(string $formPage, string $action, array $data)
    {
        $this->actingAs($this->admin);

        $page  = $this->get($formPage)->response()->getBody();
        $token = (preg_match('/name="csrf_test_name"\s+value="([^"]+)"/', (string) $page, $m) === 1) ? $m[1] : '';

        return $this->post($action, $data + ['csrf_test_name' => $token]);
    }
}
