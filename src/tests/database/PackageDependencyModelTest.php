<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Tests\Support\Fixtures;

/**
 * usedBy() is the query behind the package page's "Used By" section —
 * everything else on PackageDependencyModel (forVersion()) already has
 * coverage through PackagePublisherTest.
 *
 * @internal
 */
final class PackageDependencyModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;
    private int $feedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-deps-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
        Services::resetSingle('feedResolver');

        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $this->feedId = (int) model(FeedModel::class)->getInsertID();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    public function testUsedByFindsAPackageThatDeclaresTheDependency(): void
    {
        // Pepite.Fixtures.Deps 2.1.0 depends on Newtonsoft.Json 13.0.3. The
        // nuspec spells it "Newtonsoft.Json" — dependency_id is stored
        // exactly as authored, never folded — so matching this lowercase
        // needle against it is what proves the comparison is
        // case-insensitive, same as $dependencyIdLower's name promises: the
        // caller folds its own side, this folds the stored side.
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $result = model(PackageDependencyModel::class)->usedBy($this->feedId, 'newtonsoft.json');

        $this->assertCount(1, $result);
        $this->assertSame('Pepite.Fixtures.Deps', $result[0]['package_id']);
    }

    public function testUsedByIsScopedToTheFeed(): void
    {
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $otherFeedId = (int) model(FeedModel::class)->getInsertID();

        $this->assertSame([], model(PackageDependencyModel::class)->usedBy($otherFeedId, 'newtonsoft.json'));
    }

    public function testUsedByFindsNothingForAnUnusedDependency(): void
    {
        $this->publish('Pepite.Fixtures.Deps.2.1.0.nupkg');

        $this->assertSame([], model(PackageDependencyModel::class)->usedBy($this->feedId, 'nobody.depends.on.this'));
    }

    private function publish(string $fixture): void
    {
        $feed = model(FeedModel::class)->find($this->feedId);

        $publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            service('packageStorage'),
            db_connect(),
        );

        $publisher->publish(Fixtures::package($fixture), $feed['slug'], 1);
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
