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
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * Public browsing: only public feeds, readme rendered, private feeds hidden.
 *
 * @internal
 */
final class WebNavigationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-web-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
        Services::resetSingle('feedResolver');

        $publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            service('packageStorage'),
            db_connect(),
        );

        $publisher->publish(Fixtures::package('Pepite.Fixtures.Rich.1.2.3.nupkg'), 'default', 1);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    public function testHomeListsPublicFeeds(): void
    {
        $this->call('get', '/')->assertOK();
    }

    public function testFeedPageListsItsPackages(): void
    {
        $result = $this->call('get', 'browse/default');

        $result->assertOK();
        $result->assertSee('Pepite.Fixtures.Rich');
    }

    public function testPackagePageRendersTheReadme(): void
    {
        $result = $this->call('get', 'browse/default/pepite.fixtures.rich');

        $result->assertOK();
        $result->assertSee('Fixture exercising');
        $result->assertSee('1.2.3');
    }

    public function testASpecificVersionCanBeAddressed(): void
    {
        $this->call('get', 'browse/default/pepite.fixtures.rich/1.2.3')->assertOK();
    }

    public function testAnUnknownPackageIs404(): void
    {
        $this->call('get', 'browse/default/nope')->assertStatus(404);
    }

    public function testAPrivateFeedIsHiddenFromAnonymousBrowsing(): void
    {
        model(FeedModel::class)->where('slug', 'default')->set('visibility', 'private')->update();

        $this->call('get', 'browse/default')->assertStatus(404);
        $this->call('get', 'browse/default/pepite.fixtures.rich')->assertStatus(404);
    }

    public function testAnUnknownFeedIs404(): void
    {
        $this->call('get', 'browse/nope')->assertStatus(404);
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
