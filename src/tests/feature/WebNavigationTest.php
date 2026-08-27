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

    public function testPackagePageShowsTagsAsFilterLinksAndTheDeclaredLicense(): void
    {
        $result = $this->call('get', 'browse/default/pepite.fixtures.rich');

        $result->assertOK();
        // Pepite.Fixtures.Rich's nuspec: <tags>fixture icon readme</tags>.
        $result->assertSee('fixture');
        $result->assertSee('icon');
        $result->assertSee('readme');
        $this->assertStringContainsString(
            'href="' . site_url('browse/default') . '?q=fixture"',
            (string) $result->response()->getBody(),
        );
        // <license type="expression">MIT</license>
        $result->assertSee('MIT');
        $result->assertSee('https://example.test/pepite');
    }

    public function testPackagePageShowsTheEmbeddedIcon(): void
    {
        $result = $this->call('get', 'browse/default/pepite.fixtures.rich');

        $this->assertStringContainsString(
            'src="' . site_url('feeds/default/v3/flatcontainer/pepite.fixtures.rich/1.2.3/icon') . '"',
            (string) $result->response()->getBody(),
        );
    }

    public function testPackagePageGroupsDependenciesByTargetFramework(): void
    {
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $result = $this->call('get', 'browse/default/pepite.fixtures.deps');

        $result->assertOK();
        $result->assertSee('net10.0');
        $result->assertSee('Newtonsoft.Json');
        $result->assertSee('13.0.3');
    }

    public function testPackagePageListsWhatDependsOnIt(): void
    {
        // Deps.2.1.0 depends on Newtonsoft.Json — a row in package_dependencies
        // pointing back at Pepite.Fixtures.Rich is enough to prove the page
        // renders "Used by" correctly; it does not need a second real fixture
        // literally identified as the dependency (PackageDependencyModelTest
        // already covers the query itself against a real publish).
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');
        $depsVersionId = model(PackageVersionModel::class)
            ->join('packages', 'packages.id = package_versions.package_id')
            ->where('packages.package_id_lower', 'pepite.fixtures.deps')
            ->select('package_versions.id')
            ->first()['id'];

        model(PackageDependencyModel::class)->insert([
            'package_version_id' => $depsVersionId,
            'target_framework'   => 'net10.0',
            'dependency_id'      => 'Pepite.Fixtures.Rich',
            'version_range'      => '[1.2.3, )',
        ]);

        $result = $this->call('get', 'browse/default/pepite.fixtures.rich');

        $result->assertOK();
        $result->assertSee('Used by');
        $result->assertSee('Pepite.Fixtures.Deps');
    }

    public function testFeedPageCanSortByName(): void
    {
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $result = $this->call('get', 'browse/default?sort=name');

        $result->assertOK();
        $body   = (string) $result->response()->getBody();
        $depsAt = strpos($body, 'Pepite.Fixtures.Deps');
        $richAt = strpos($body, 'Pepite.Fixtures.Rich');
        $this->assertNotFalse($depsAt);
        $this->assertNotFalse($richAt);
        $this->assertLessThan($richAt, $depsAt, 'Deps should sort before Rich alphabetically.');
    }

    public function testRecentAtomFeedListsPublishedVersions(): void
    {
        $result = $this->call('get', 'browse/default/recent.atom');

        $result->assertOK();
        $this->assertStringContainsString('application/atom+xml', $result->response()->getHeaderLine('Content-Type'));

        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $body);
        $this->assertStringContainsString('Pepite.Fixtures.Rich', $body);
        $this->assertStringContainsString('1.2.3', $body);

        // Well-formed XML, not just string matches — the debug toolbar used
        // to wrap this in an HTML comment ahead of the XML declaration in
        // dev/testing; view()'s ['debug' => false] option is what stops it.
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($body);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($xml);
    }

    public function testRecentAtomFeedIsHiddenForAPrivateFeed(): void
    {
        model(FeedModel::class)->where('slug', 'default')->set('visibility', 'private')->update();

        $this->call('get', 'browse/default/recent.atom')->assertStatus(404);
    }

    public function testGlobalSearchFindsPackagesAcrossPublicFeeds(): void
    {
        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg', 'other');

        $result = $this->call('get', 'search');

        $result->assertOK();
        $result->assertSee('Pepite.Fixtures.Rich');
        $result->assertSee('Pepite.Fixtures.Deps');
        // A global result names the feed it came from, unlike a per-feed one.
        $result->assertSee('Other');
    }

    public function testGlobalSearchExcludesPrivateFeeds(): void
    {
        model(FeedModel::class)->insert(['slug' => 'secret', 'name' => 'Secret', 'visibility' => 'private']);
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg', 'secret');

        $result = $this->call('get', 'search');

        $result->assertOK();
        $result->assertSee('Pepite.Fixtures.Rich');
        $result->assertDontSee('Pepite.Fixtures.Deps');
    }

    public function testGlobalSearchHonoursTheQueryAndSort(): void
    {
        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg', 'other');

        $result = $this->call('get', 'search?q=Deps');

        $result->assertOK();
        $result->assertDontSee('Pepite.Fixtures.Rich');
        $result->assertSee('Pepite.Fixtures.Deps');
    }

    public function testPackagePageShowsAPrereleaseBadge(): void
    {
        $this->publish('Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg');

        $result = $this->call('get', 'browse/default/pepite.fixtures.prerelease');

        $result->assertOK();
        $result->assertSee('prerelease');
    }

    public function testFeedListingShowsAPrereleaseBadge(): void
    {
        $this->publish('Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg');

        $result = $this->call('get', 'browse/default');

        $result->assertOK();
        $result->assertSee('prerelease');
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

    private function publish(string $fixture, string $feedSlug = 'default'): void
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
