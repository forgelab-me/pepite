<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Libraries\Version\NuGetVersion;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * The read side of the V3 protocol.
 *
 * tools/test-restore.sh proves conformance with the real client; these tests
 * pin the details that a passing restore would not notice — SemVer 2
 * filtering, delisting, absolute URLs — and catch regressions without needing
 * a server and the .NET SDK.
 *
 * @internal
 */
final class V3ReadEndpointsTest extends CIUnitTestCase
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

        $this->storageRoot = sys_get_temp_dir() . '/pepite-v3-' . bin2hex(random_bytes(6));
        $storage           = new PackageStorage($this->storageRoot, 4194304);

        Services::injectMock('packageStorage', $storage);
        Services::resetSingle('feedResolver');

        $publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            $storage,
            db_connect(),
        );

        foreach ([
            'Pepite.Fixtures.Simple.1.0.0.nupkg',
            'Pepite.Fixtures.Deps.2.1.0.nupkg',
            'Pepite.Fixtures.Rich.1.2.3.nupkg',
            'Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg',
            'Pepite.Fixtures.Legacy.1.2.3.4.nupkg',
        ] as $fixture) {
            $publisher->publish(Fixtures::package($fixture), 'default', 1);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- index

    public function testServiceIndexAdvertisesEveryRequiredResource(): void
    {
        $index = $this->json('feeds/default/v3/index.json');

        $this->assertSame('3.0.0', $index['version']);

        $types = array_column($index['resources'], '@type');

        foreach ([
            'PackageBaseAddress/3.0.0',
            'RegistrationsBaseUrl/3.6.0',
            'SearchQueryService/3.5.0',
            'SearchAutocompleteService/3.5.0',
            'PackagePublish/2.0.0',
            'SymbolPackagePublish/4.9.0',
        ] as $required) {
            $this->assertContains($required, $types);
        }
    }

    /**
     * Older clients look up the unversioned type names and simply do not see a
     * resource advertised only under the newest one. nuget.org itself
     * publishes each capability several times over.
     */
    public function testServiceIndexAdvertisesLegacyTypeAliases(): void
    {
        $types = array_column($this->json('feeds/default/v3/index.json')['resources'], '@type');

        foreach ([
            'RegistrationsBaseUrl',
            'RegistrationsBaseUrl/3.0.0-beta',
            'SearchQueryService',
            'SearchQueryService/3.0.0-beta',
            'SearchAutocompleteService',
        ] as $alias) {
            $this->assertContains($alias, $types);
        }
    }

    /**
     * SemVer 2 filtering in registration is two base URLs, not a query
     * parameter — so the two must be advertised, and they must differ.
     */
    public function testTheTwoRegistrationBasesAreDistinct(): void
    {
        $resources = $this->json('feeds/default/v3/index.json')['resources'];

        $byType = [];

        foreach ($resources as $resource) {
            $byType[$resource['@type']] = $resource['@id'];
        }

        $this->assertNotSame($byType['RegistrationsBaseUrl'], $byType['RegistrationsBaseUrl/3.6.0']);
    }

    public function testEveryAdvertisedUrlIsAbsolute(): void
    {
        foreach ($this->json('feeds/default/v3/index.json')['resources'] as $resource) {
            // A relative or foreign-host URL loses the client's credentials on
            // a private feed, and the restore dies on an opaque 401.
            $this->assertStringStartsWith('http', $resource['@id']);
            $this->assertStringContainsString('/feeds/default/', $resource['@id']);
        }
    }

    // ------------------------------------------------------- flat container

    public function testFlatContainerListsVersionsFoldedAndOrdered(): void
    {
        $this->assertSame(
            ['1.0.0'],
            $this->json('feeds/default/v3/flatcontainer/pepite.fixtures.simple/index.json')['versions'],
        );

        $this->assertSame(
            ['1.0.0-beta.2'],
            $this->json('feeds/default/v3/flatcontainer/pepite.fixtures.prerelease/index.json')['versions'],
        );
    }

    /**
     * The flat container hides nothing: a delisted version stays downloadable,
     * because anything already depending on it must keep restoring.
     */
    public function testFlatContainerStillListsDelistedVersions(): void
    {
        $this->delist('pepite.fixtures.simple');

        $this->assertSame(
            ['1.0.0'],
            $this->json('feeds/default/v3/flatcontainer/pepite.fixtures.simple/index.json')['versions'],
        );
    }

    /**
     * The archive is streamed rather than buffered, because a package can be
     * tens of megabytes and memory_limit is rarely generous on shared hosting.
     * That is also why the body cannot be compared here: a DownloadResponse
     * writes straight to the output, so it carries no body in a test.
     *
     * What is asserted instead is the announced length. Byte fidelity end to
     * end is proven by tools/test-restore.sh, where the real client verifies
     * the content hash it downloaded.
     */
    public function testServesThePackageAsAStreamOfTheRightLength(): void
    {
        $result = $this->call(
            'get',
            'feeds/default/v3/flatcontainer/pepite.fixtures.simple/1.0.0/pepite.fixtures.simple.1.0.0.nupkg',
        );

        $result->assertStatus(200);

        $response = $result->response();

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertSame(
            filesize(Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg')),
            $response->getContentLength(),
        );
    }

    /**
     * Only the archive itself counts as a download — nuspec, icon and readme
     * are fetched incidentally by tooling and nuget.org does not count them
     * either.
     */
    public function testDownloadingTheArchiveCountsAsADownload(): void
    {
        $package = model(PackageModel::class)->findInFeed(1, 'Pepite.Fixtures.Simple');
        $version = model(PackageVersionModel::class)->findVersion(
            (int) $package['id'],
            NuGetVersion::parse('1.0.0'),
        );

        $this->assertSame(0, (int) $version['downloads']);
        $this->assertSame(0, (int) $package['total_downloads']);

        $this->call(
            'get',
            'feeds/default/v3/flatcontainer/pepite.fixtures.simple/1.0.0/pepite.fixtures.simple.1.0.0.nupkg',
        )->assertStatus(200);

        $this->call('get', 'feeds/default/v3/flatcontainer/pepite.fixtures.rich/1.2.3/icon')->assertOK();

        $this->assertSame(1, (int) model(PackageVersionModel::class)->find($version['id'])['downloads']);
        $this->assertSame(1, (int) model(PackageModel::class)->find($package['id'])['total_downloads']);
    }

    public function testServesTheManifestAndTheExtractedAssets(): void
    {
        $nuspec = $this->call('get', 'feeds/default/v3/flatcontainer/pepite.fixtures.rich/1.2.3/pepite.fixtures.rich.nuspec');
        $nuspec->assertOK();
        $this->assertStringContainsString('<id>Pepite.Fixtures.Rich</id>', $nuspec->response()->getBody());

        $icon = $this->call('get', 'feeds/default/v3/flatcontainer/pepite.fixtures.rich/1.2.3/icon');
        $icon->assertOK();
        $this->assertSame("\x89PNG", substr($icon->response()->getBody(), 0, 4));

        $readme = $this->call('get', 'feeds/default/v3/flatcontainer/pepite.fixtures.rich/1.2.3/readme');
        $readme->assertOK();
        $this->assertStringContainsString('Fixture exercising', $readme->response()->getBody());
    }

    public function testAPackageWithoutAnIconAnswers404(): void
    {
        $this->call('get', 'feeds/default/v3/flatcontainer/pepite.fixtures.simple/1.0.0/icon')
            ->assertStatus(404);
    }

    // ---------------------------------------------------------- registration

    public function testRegistrationCarriesDependenciesWithNormalizedRanges(): void
    {
        $index = $this->json('feeds/default/v3/registration/pepite.fixtures.deps/index.json');

        $this->assertSame(1, $index['count']);
        $this->assertSame(['catalog:CatalogRoot', 'PackageRegistration', 'catalog:Permalink'], $index['@type']);

        $entry = $index['items'][0]['items'][0]['catalogEntry'];

        $this->assertSame('Pepite.Fixtures.Deps', $entry['id']);
        $this->assertSame('2.1.0', $entry['version']);

        $ranges = [];

        foreach ($entry['dependencyGroups'] as $group) {
            foreach ($group['dependencies'] as $dependency) {
                $ranges[$group['targetFramework'] . '/' . $dependency['id']] = $dependency['range'];
            }
        }

        // The spelling the client expects, space after the comma included.
        $this->assertSame('[13.0.3, )', $ranges['net10.0/Newtonsoft.Json']);
        $this->assertSame('[8.0.5, )', $ranges['.NETStandard2.0/System.Text.Json']);
    }

    public function testRegistrationPointsAtDownloadableContent(): void
    {
        $index = $this->json('feeds/default/v3/registration/pepite.fixtures.simple/index.json');
        $leaf  = $index['items'][0]['items'][0];

        $this->assertSame($leaf['packageContent'], $leaf['catalogEntry']['packageContent']);
        $this->assertStringContainsString(
            '/flatcontainer/pepite.fixtures.simple/1.0.0/pepite.fixtures.simple.1.0.0.nupkg',
            $leaf['packageContent'],
        );
    }

    /**
     * A client that has not announced SemVer 2 support cannot parse these
     * versions, so the SemVer 1 base must not mention them at all.
     */
    public function testSemVer2VersionsAreInvisibleOnTheSemVer1Base(): void
    {
        $this->call('get', 'feeds/default/v3/registration/pepite.fixtures.prerelease/index.json')
            ->assertStatus(404);

        $index = $this->json('feeds/default/v3/registration-semver2/pepite.fixtures.prerelease/index.json');
        $this->assertSame('1.0.0-beta.2', $index['items'][0]['items'][0]['catalogEntry']['version']);
    }

    /**
     * A four-segment version predates SemVer entirely and is not a SemVer 2
     * feature, so it belongs on both bases.
     */
    public function testFourSegmentVersionsAppearOnBothBases(): void
    {
        foreach (['registration', 'registration-semver2'] as $base) {
            $index = $this->json(sprintf('feeds/default/v3/%s/pepite.fixtures.legacy/index.json', $base));

            $this->assertSame('1.2.3.4', $index['items'][0]['items'][0]['catalogEntry']['version'], $base);
        }
    }

    public function testRegistrationLeafIsServedOnItsOwn(): void
    {
        $leaf = $this->json('feeds/default/v3/registration/pepite.fixtures.simple/1.0.0.json');

        $this->assertSame(['Package', 'catalog:Permalink'], $leaf['@type']);
        $this->assertTrue($leaf['listed']);
        $this->assertStringContainsString('.nupkg', $leaf['packageContent']);
    }

    public function testDelistingShowsInRegistrationWithoutRemovingTheVersion(): void
    {
        $this->delist('pepite.fixtures.simple');

        $index = $this->json('feeds/default/v3/registration/pepite.fixtures.simple/index.json');

        $this->assertFalse($index['items'][0]['items'][0]['catalogEntry']['listed']);
    }

    // --------------------------------------------------------------- search

    public function testSearchReturnsTheLatestVersionAndTheFullList(): void
    {
        $results = $this->json('feeds/default/v3/search?q=simple');

        $this->assertSame(1, $results['totalHits']);

        $entry = $results['data'][0];

        $this->assertSame('Pepite.Fixtures.Simple', $entry['id']);
        $this->assertSame('1.0.0', $entry['version']);
        $this->assertSame([['name' => 'Dependency']], $entry['packageTypes']);
        $this->assertSame(['1.0.0'], array_column($entry['versions'], 'version'));
    }

    public function testSearchHidesPrereleasesUnlessAsked(): void
    {
        $this->assertSame(0, $this->json('feeds/default/v3/search?q=prerelease')['totalHits']);

        $this->assertSame(
            1,
            $this->json('feeds/default/v3/search?q=prerelease&prerelease=true&semVerLevel=2.0.0')['totalHits'],
        );
    }

    public function testSearchHidesSemVer2FromClientsThatDidNotAskForIt(): void
    {
        // Prerelease allowed, but the client never announced SemVer 2 support.
        $this->assertSame(0, $this->json('feeds/default/v3/search?q=prerelease&prerelease=true')['totalHits']);
    }

    public function testSearchExcludesDelistedPackages(): void
    {
        $this->delist('pepite.fixtures.simple');

        $this->assertSame(0, $this->json('feeds/default/v3/search?q=simple')['totalHits']);
    }

    public function testAnEmptyQueryListsTheWholeFeed(): void
    {
        $results = $this->json('feeds/default/v3/search?q=&take=100');

        // Everything except the SemVer 2 prerelease, which was not asked for.
        $this->assertSame(4, $results['totalHits']);
    }

    /**
     * The filter that lets one server hold libraries and applications without
     * the client having to sort them out.
     */
    public function testSearchFiltersOnPackageType(): void
    {
        $this->assertSame(4, $this->json('feeds/default/v3/search?take=100&packageType=Dependency')['totalHits']);
        $this->assertSame(0, $this->json('feeds/default/v3/search?take=100&packageType=ConsoleApp')['totalHits']);
    }

    public function testSearchContextPointsAtTheRegistrationBaseInUse(): void
    {
        $plain   = $this->json('feeds/default/v3/search?q=simple');
        $semVer2 = $this->json('feeds/default/v3/search?q=simple&semVerLevel=2.0.0');

        $this->assertStringEndsWith('/v3/registration/', $plain['@context']['@base']);
        $this->assertStringEndsWith('/v3/registration-semver2/', $semVer2['@context']['@base']);
    }

    public function testAutocompleteCompletesIdentifiersAndVersions(): void
    {
        $ids = $this->json('feeds/default/v3/autocomplete?q=pepite.fixtures.d');
        $this->assertSame(['Pepite.Fixtures.Deps'], $ids['data']);

        $versions = $this->json('feeds/default/v3/autocomplete?id=Pepite.Fixtures.Deps');
        $this->assertSame(['2.1.0'], $versions['data']);
    }

    // --------------------------------------------------------------- misfits

    public function testAnUnknownFeedIsNotFound(): void
    {
        $this->call('get', 'feeds/nope/v3/index.json')->assertStatus(404);
    }

    public function testAnUnknownPackageIsNotFound(): void
    {
        $this->call('get', 'feeds/default/v3/flatcontainer/nope/index.json')->assertStatus(404);
        $this->call('get', 'feeds/default/v3/registration/nope/index.json')->assertStatus(404);
    }

    public function testIdentifiersAreMatchedWithoutRegardForCase(): void
    {
        $this->call('get', 'feeds/default/v3/flatcontainer/PePiTe.FiXtUrEs.SiMpLe/index.json')->assertOK();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        $result = $this->call('get', $path);
        $result->assertOK();

        return json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function delist(string $packageIdLower): void
    {
        $package = model(PackageModel::class)->where('package_id_lower', $packageIdLower)->first();

        model(PackageVersionModel::class)
            ->where('package_id', (int) $package['id'])
            ->set('is_listed', false)
            ->update();
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
