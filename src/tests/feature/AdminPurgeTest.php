<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * The console equivalent of `pepite:purge` — permanently deleting a package
 * or one of its versions from the admin console instead of a shell. Gated
 * one level narrower than the rest of the admin console: 'admin' alone is
 * not enough, 'superadmin' is required too (Packages::requireSuperadmin()).
 *
 * @internal
 */
final class AdminPurgeTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-adminpurge-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();
        auth('session')->getAuthenticator()->logout();

        parent::tearDown();
    }

    public function testAPlainAdminIsRefusedAndNothingIsDeleted(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');

        $admin = $this->user('plain-admin@pepite.test', ['admin']);

        $result = $this->postWithCsrf($admin, 'admin/feeds/' . $feedId . '/packages/' . $package['id'], 'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/purge', [
            'confirm' => 'Pepite.Fixtures.Rich',
        ]);

        $result->assertRedirectTo(site_url('admin/feeds/' . $feedId . '/packages/' . $package['id']));
        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
    }

    /**
     * A superadmin who is NOT also in 'admin' is refused at the admin/*
     * group's own group:admin route filter, never reaching the controller
     * at all — a different layer than the case above, and a regression
     * guard against ever collapsing the two group filters into one
     * (group:admin,superadmin), which would flip this from AND to OR.
     */
    public function testASuperadminOnlyUserWithoutPlainAdminIsRefusedAtTheRouteFilter(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');

        $superadminOnly = $this->user('superadmin-only@pepite.test', ['superadmin']);

        // No CSRF token fetched on purpose: group:admin runs before csrf in
        // this route's filter chain, so a non-admin never reaches the CSRF
        // check at all — any POST here is refused at the group filter.
        $result = $this->actingAs($superadminOnly)->post(
            'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/purge',
            ['confirm' => 'Pepite.Fixtures.Rich'],
        );

        $result->assertRedirectTo(rtrim(site_url('/'), '/'));
        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
    }

    public function testASuperadminCanDeleteAWholePackage(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');
        $nupkg   = service('packageStorage')->absolute((string) model(PackageVersionModel::class)->forPackage((int) $package['id'])[0]['nupkg_path']);

        $superadmin = $this->user('superadmin@pepite.test', ['admin', 'superadmin']);

        $this->postWithCsrf($superadmin, 'admin/feeds/' . $feedId . '/packages/' . $package['id'], 'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/purge', [
            'confirm' => 'Pepite.Fixtures.Rich',
        ])->assertRedirectTo(site_url('admin/feeds/' . $feedId . '/packages'));

        $this->assertNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
        $this->assertFileDoesNotExist($nupkg);
    }

    public function testASuperadminCanDeleteOneVersionAndThePackageSurvives(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');
        $this->addSecondVersion((int) $package['id'], $feedId, (string) $package['package_id_lower']);

        $oldest     = model(PackageVersionModel::class)->forPackage((int) $package['id'])[0];
        $superadmin = $this->user('superadmin@pepite.test', ['admin', 'superadmin']);

        $this->postWithCsrf(
            $superadmin,
            'admin/feeds/' . $feedId . '/packages/' . $package['id'],
            'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/versions/' . $oldest['id'] . '/purge',
            ['confirm' => 'Pepite.Fixtures.Rich'],
        )->assertRedirectTo(site_url('admin/feeds/' . $feedId . '/packages/' . $package['id']));

        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
        $this->assertCount(1, model(PackageVersionModel::class)->forPackage((int) $package['id']));
    }

    public function testDeletingTheLastVersionAlsoRemovesThePackage(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');
        $version = model(PackageVersionModel::class)->forPackage((int) $package['id'])[0];

        $superadmin = $this->user('superadmin@pepite.test', ['admin', 'superadmin']);

        $this->postWithCsrf(
            $superadmin,
            'admin/feeds/' . $feedId . '/packages/' . $package['id'],
            'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/versions/' . $version['id'] . '/purge',
            ['confirm' => 'Pepite.Fixtures.Rich'],
        )->assertRedirectTo(site_url('admin/feeds/' . $feedId . '/packages'));

        $this->assertNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
    }

    public function testAWrongConfirmationValueDeletesNothing(): void
    {
        $feedId  = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $package = $this->publishAndFind('Pepite.Fixtures.Rich.1.2.3.nupkg');

        $superadmin = $this->user('superadmin@pepite.test', ['admin', 'superadmin']);

        $this->postWithCsrf($superadmin, 'admin/feeds/' . $feedId . '/packages/' . $package['id'], 'admin/feeds/' . $feedId . '/packages/' . $package['id'] . '/purge', [
            'confirm' => 'not the right identifier',
        ]);

        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
    }

    /**
     * @param list<string> $groups
     */
    private function user(string $email, array $groups): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['username' => explode('@', $email)[0], 'email' => $email, 'password' => 'pepite-test-2026']));
        $user = $users->findById($users->getInsertID());

        foreach ($groups as $group) {
            $user->addGroup($group);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function publishAndFind(string $fixture, string $feedSlug = 'default'): array
    {
        $publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            service('packageStorage'),
            db_connect(),
        );

        $publisher->publish(Fixtures::package($fixture), $feedSlug, 1);

        $feedId = (int) model(FeedModel::class)->findBySlug($feedSlug)['id'];

        // Read back the just-published row rather than re-deriving the
        // identifier from the fixture filename — Id.Version.nupkg, and the
        // identifier itself may contain dots.
        return model(PackageModel::class)->where('feed_id', $feedId)->orderBy('id', 'DESC')->first();
    }

    private function addSecondVersion(int $packageId, int $feedId, string $packageIdLower): void
    {
        $storage  = service('packageStorage');
        $relative = sprintf('packages/%d/%s/9.9.9/%s.9.9.9.nupkg', $feedId, $packageIdLower, $packageIdLower);
        $absolute = $storage->absolute($relative);

        mkdir(dirname($absolute), 0o775, true);
        file_put_contents($absolute, 'not a real package, just needs to exist on disk');

        model(PackageVersionModel::class)->insert([
            'package_id'               => $packageId,
            'version_original'         => '9.9.9',
            'version_normalized'       => '9.9.9',
            'version_normalized_lower' => '9.9.9',
            'version_sort_key'         => '9.9.9',
            'is_listed'                => true,
            'nupkg_path'               => $relative,
            'nuspec_path'              => $relative,
            'sha512_base64'            => base64_encode(hash('sha512', 'fixture', true)),
        ]);
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
}
