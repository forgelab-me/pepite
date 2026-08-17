<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\PublishException;
use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class PackagePublisherTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;
    private PackagePublisher $publisher;
    private PackageStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-storage-' . bin2hex(random_bytes(6));
        $this->storage     = new PackageStorage($this->storageRoot, 4194304);

        $this->publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            $this->storage,
            db_connect(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function testTheMigrationSeedsADefaultFeed(): void
    {
        $feed = model(FeedModel::class)->findBySlug('default');

        $this->assertNotNull($feed, 'a server without a feed can do nothing');
        $this->assertSame('public', $feed['visibility']);
    }

    public function testPublishesAPackageAndItsBlobs(): void
    {
        $result = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $this->assertSame('Pepite.Fixtures.Simple', $result->metadata->id);
        $this->assertTrue($result->claimedNewIdentifier);

        $this->seeInDatabase('packages', [
            'package_id'       => 'Pepite.Fixtures.Simple',
            'package_id_lower' => 'pepite.fixtures.simple',
        ]);

        $version = model(PackageVersionModel::class)->find($result->versionRowId);

        $this->assertSame('1.0.0', $version['version_normalized']);
        $this->assertTrue($this->storage->exists($version['nupkg_path']));
        $this->assertTrue($this->storage->exists($version['nuspec_path']));

        // The stored archive is byte-for-byte the one published.
        $this->assertSame(
            hash_file('sha512', Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg')),
            hash_file('sha512', $this->storage->absolute($version['nupkg_path'])),
        );
    }

    public function testStoresIdentityFieldsInBothSpellings(): void
    {
        $result  = $this->publish('Pepite.Fixtures.Prerelease.1.0.0-beta.2.nupkg');
        $version = model(PackageVersionModel::class)->find($result->versionRowId);

        // Build metadata survives for display but not in the identity.
        $this->assertSame('1.0.0-beta.2+build.5', $version['version_original']);
        $this->assertSame('1.0.0-beta.2', $version['version_normalized']);
        $this->assertSame('1.0.0-beta.2', $version['version_normalized_lower']);
        $this->assertSame(1, (int) $version['is_prerelease']);
        $this->assertSame(2, (int) $version['semver_level'], 'dotted prerelease labels are SemVer 2');
    }

    public function testAFourSegmentVersionIsNotSemVer2(): void
    {
        $result  = $this->publish('Pepite.Fixtures.Legacy.1.2.3.4.nupkg');
        $version = model(PackageVersionModel::class)->find($result->versionRowId);

        $this->assertSame('1.2.3.4', $version['version_normalized']);
        $this->assertSame(0, (int) $version['semver_level']);
    }

    public function testStoresDependencyGroupsWithNormalizedRanges(): void
    {
        $result = $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $rows = model(PackageDependencyModel::class)->forVersion($result->versionRowId);

        // One dependency under net10.0, two under netstandard2.0.
        $this->assertCount(3, $rows);

        $frameworks = array_unique(array_column($rows, 'target_framework'));
        sort($frameworks);
        $this->assertSame(['.NETStandard2.0', 'net10.0'], $frameworks);

        $ranges = [];

        foreach ($rows as $row) {
            $ranges[$row['target_framework'] . '/' . $row['dependency_id']] = $row['version_range'];
        }

        // Stored ready to serve: a registration document must carry this exact
        // spelling, space after the comma included.
        $this->assertSame([
            'net10.0/Newtonsoft.Json'          => '[13.0.3, )',
            '.NETStandard2.0/Newtonsoft.Json'  => '[13.0.3, )',
            '.NETStandard2.0/System.Text.Json' => '[8.0.5, )',
        ], $ranges);
    }

    public function testExtractsEmbeddedIconAndReadme(): void
    {
        $result  = $this->publish('Pepite.Fixtures.Rich.1.2.3.nupkg');
        $version = model(PackageVersionModel::class)->find($result->versionRowId);

        $this->assertNotNull($version['icon_path']);
        $this->assertNotNull($version['readme_path']);
        $this->assertTrue($this->storage->exists($version['icon_path']));
        $this->assertTrue($this->storage->exists($version['readme_path']));

        // A search response has to expose an absolute iconUrl, which is only
        // possible because the icon was pulled out of the archive at publish.
        $this->assertSame(
            "\x89PNG",
            substr(file_get_contents($this->storage->absolute($version['icon_path'])), 0, 4),
        );
    }

    public function testPublishedVersionsAreImmutable(): void
    {
        $first = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg');
        $path  = model(PackageVersionModel::class)->find($first->versionRowId)['nupkg_path'];

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg');
            $this->fail('a second push of the same version must be refused');
        } catch (PublishException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertTrue($this->storage->exists($path), 'the refused push must not disturb what is stored');
        $this->assertSame(1, model(PackageVersionModel::class)->countAllResults());
    }

    public function testRefusesAnUnknownFeed(): void
    {
        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', 'nope');
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testRefusesANewIdentifierOnAClosedFeed(): void
    {
        model(FeedModel::class)->set('allow_new_packages', false)->where('slug', 'default')->update();

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg');
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame(0, model(PackageModel::class)->countAllResults());
    }

    /**
     * The guard that makes several feeds usable: a package aimed at the wrong
     * one is refused rather than quietly accepted.
     */
    public function testRefusesAPackageTypeTheFeedDoesNotAccept(): void
    {
        model(FeedModel::class)
            ->set('allowed_package_types', json_encode(['ConsoleApp']))
            ->where('slug', 'default')
            ->update();

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg');
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('Dependency', $e->getMessage());
        }

        // Nothing written on the way out.
        $this->assertSame(0, model(PackageVersionModel::class)->countAllResults());
        $this->assertDirectoryDoesNotExist($this->storageRoot . '/packages');
    }

    public function testFirstPushClaimsTheIdentifier(): void
    {
        $result = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 42);

        $this->assertTrue(model(PackageOwnerModel::class)->owns($result->packageRowId, 42));
        $this->assertSame([42], model(PackageOwnerModel::class)->userIdsFor($result->packageRowId));
    }

    public function testASecondVersionDoesNotClaimTheIdentifierAgain(): void
    {
        $first = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 42);

        // Same identifier reaching the feed a second time, by another user.
        model(PackageVersionModel::class)->where('package_id', $first->packageRowId)->delete();
        $second = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 7);

        $this->assertFalse($second->claimedNewIdentifier);
        $this->assertSame($first->packageRowId, $second->packageRowId);

        $owners = model(PackageOwnerModel::class)->userIdsFor($first->packageRowId);
        sort($owners);

        // Ownership is additive here because authorisation is not this
        // service's job; deciding who may push arrives with lot 5.
        $this->assertSame([7, 42], $owners);
    }

    private function publish(string $fixture, string $feed = 'default', ?int $owner = null)
    {
        return $this->publisher->publish(Fixtures::package($fixture), $feed, $owner);
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
