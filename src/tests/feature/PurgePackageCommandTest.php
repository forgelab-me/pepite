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
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * `spark pepite:purge` — the one deliberate way to make a published version
 * (or a whole package) stop existing, database rows and stored files alike.
 * Everything else in the app treats a publish as permanent on purpose; this
 * command exists for the case that guarantee cannot survive, e.g. a file
 * that should never have been public at all.
 *
 * @internal
 */
final class PurgePackageCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-purge-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    public function testDeletingTheOnlyVersionAlsoRemovesThePackageAndItsFiles(): void
    {
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $this->publish('Pepite.Fixtures.Rich.1.2.3.nupkg');

        $package = model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich');
        $version = model(PackageVersionModel::class)->forPackage((int) $package['id'])[0];
        $nupkg   = service('packageStorage')->absolute((string) $version['nupkg_path']);

        $this->assertFileExists($nupkg);

        command('pepite:purge default Pepite.Fixtures.Rich --yes');

        $this->assertNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
        $this->assertSame([], model(PackageVersionModel::class)->where('package_id', $package['id'])->findAll());
        $this->assertFileDoesNotExist($nupkg);
    }

    public function testDeletingOneVersionLeavesTheOthersAndThePackageAlone(): void
    {
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $this->publish('Pepite.Fixtures.Rich.1.2.3.nupkg');
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $package = model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich');
        $oldest  = model(PackageVersionModel::class)->forPackage((int) $package['id'])[0];
        $nupkg   = service('packageStorage')->absolute((string) $oldest['nupkg_path']);

        // A second version, so deleting 1.2.3 has something to leave behind —
        // the fixtures directory only ships one .nupkg per identity.
        $this->addSecondVersion((int) $package['id'], $feedId, (string) $package['package_id_lower']);

        command('pepite:purge default Pepite.Fixtures.Rich 1.2.3 --yes');

        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
        $this->assertCount(1, model(PackageVersionModel::class)->forPackage((int) $package['id']));
        $this->assertFileDoesNotExist($nupkg);
        // The unrelated package published alongside it is untouched.
        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Deps'));
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

    public function testDeletingAVersionAlsoDeletesDependenciesDeclaredAgainstIt(): void
    {
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $package = model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Deps');
        $version = model(PackageVersionModel::class)->forPackage((int) $package['id'])[0];

        $this->assertNotSame([], model(PackageDependencyModel::class)->forVersion((int) $version['id']));

        command('pepite:purge default Pepite.Fixtures.Deps --yes');

        $this->assertSame([], model(PackageDependencyModel::class)->forVersion((int) $version['id']));
    }

    public function testWithoutConfirmationNothingIsDeleted(): void
    {
        $feedId = (int) model(FeedModel::class)->findBySlug('default')['id'];
        $this->publish('Pepite.Fixtures.Rich.1.2.3.nupkg');

        command('pepite:purge default Pepite.Fixtures.Rich');

        $this->assertNotNull(model(PackageModel::class)->findInFeed($feedId, 'Pepite.Fixtures.Rich'));
    }

    public function testAnUnknownFeedOrPackageIsRefused(): void
    {
        $this->resetStreamFilterBuffer();
        command('pepite:purge nope Pepite.Fixtures.Rich --yes');
        $this->assertStringContainsString('No feed named', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        command('pepite:purge default Nope.Nope --yes');
        $this->assertStringContainsString('No package named', $this->getStreamFilterBuffer());
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
