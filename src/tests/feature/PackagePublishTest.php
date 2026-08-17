<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filters\NuGetApiKey;
use App\Libraries\PackageStorage;
use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Tests\Support\Fixtures;
use Tests\Support\Fixtures\Http\MultipartBuilder;

/**
 * The publish side: PUT (push), DELETE (unlist), POST (relist).
 *
 * The real client is exercised by hand against a running server — a genuine
 * `dotnet nuget push` and `dotnet nuget delete` — because that is the only
 * thing that proves the multipart body PHP receives from a live client is the
 * one this code expects. These tests pin the behaviour a passing push would
 * not by itself demonstrate: authorisation, conflicts, and the codes each
 * failure must answer with.
 *
 * @internal
 */
final class PackagePublishTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;
    private string $pushKey;
    private string $readOnlyKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-publish-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
        Services::resetSingle('feedResolver');

        $pusher        = $this->createUser('pusher@pepite.test');
        $this->pushKey = $pusher->generateAccessToken('test', [
            'packages.read',
            NuGetApiKey::SCOPE_PUSH,
            NuGetApiKey::SCOPE_UNLIST,
        ])->raw_token;

        $reader            = $this->createUser('reader@pepite.test');
        $this->readOnlyKey = $reader->generateAccessToken('test', ['packages.read'])->raw_token;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::reset();

        parent::tearDown();
    }

    // -------------------------------------------------------------- pushing

    public function testPushesARealPackageAndItLandsInTheFeed(): void
    {
        $result = $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $result->assertStatus(201);

        $stored = model(PackageVersionModel::class)->first();

        $this->assertNotNull($stored);
        $this->assertSame('1.0.0', $stored['version_normalized']);

        // The stored archive is byte-for-byte what the multipart body carried.
        $this->assertSame(
            hash_file('sha512', Fixtures::package('Pepite.Fixtures.Simple.1.0.0.nupkg')),
            hash_file('sha512', service('packageStorage')->absolute($stored['nupkg_path'])),
        );
    }

    public function testFirstPushClaimsOwnershipForTheAuthenticatedUser(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        $packageId = (int) model(PackageModel::class)->first()['id'];
        $pusher    = model(UserModel::class)->findByCredentials(['email' => 'pusher@pepite.test']);

        $this->assertTrue(model(PackageOwnerModel::class)->owns($packageId, (int) $pusher->id));
    }

    public function testRepublishingTheSameVersionAnswers409(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        $result = $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $result->assertStatus(409);
        $this->assertSame(1, model(PackageVersionModel::class)->countAllResults());
    }

    public function testPushingToAnUnknownFeedAnswers404(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg', feed: 'nope')->assertStatus(404);
    }

    public function testPushingAPackageTypeTheFeedRejectsAnswers400(): void
    {
        model(FeedModel::class)
            ->set('allowed_package_types', json_encode(['ConsoleApp']))
            ->where('slug', 'default')
            ->update();

        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(400);
    }

    public function testAMalformedArchiveAnswers400(): void
    {
        $body = MultipartBuilder::withFile('package', 'bad.nupkg', 'not a zip file at all');

        $result = $this->withHeaders([
            'X-NuGet-ApiKey' => $this->pushKey,
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/default/api/v2/package');

        $result->assertStatus(400);
        $this->assertSame(0, model(PackageVersionModel::class)->countAllResults());
    }

    public function testARequestWithoutAFileAnswers400(): void
    {
        $body = "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nno file here\r\n--X--\r\n";

        $result = $this->withHeaders([
            'X-NuGet-ApiKey' => $this->pushKey,
            'Content-Type'   => 'multipart/form-data; boundary=X',
        ])->withBody($body)->call('put', 'feeds/default/api/v2/package');

        $result->assertStatus(400);
    }

    // --------------------------------------------------------- symbol pushes

    /**
     * A .snupkg pushed after its package must succeed and be stored, never
     * served: PLAN.md 4.4. Refusing it outright would make `dotnet nuget push`
     * fail whenever symbols sit next to a package, which is a default build
     * output.
     */
    public function testAcceptsAndStoresASymbolPackageAfterItsPackage(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        // A .snupkg is a .nupkg under the hood; a fixture package doubles as
        // a stand-in symbol package for the purpose of this test.
        $body = MultipartBuilder::withFile(
            'package',
            'symbols.snupkg',
            Fixtures::contents('Packages/Pepite.Fixtures.Simple.1.0.0.nupkg'),
        );

        $result = $this->withHeaders([
            'X-NuGet-ApiKey' => $this->pushKey,
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/default/api/v2/symbolpackage');

        $result->assertStatus(201);

        $stored = model(PackageVersionModel::class)->first();
        $this->assertNotNull($stored['snupkg_path']);
        $this->assertTrue(service('packageStorage')->exists($stored['snupkg_path']));
    }

    public function testRefusesSymbolsForAPackageThatWasNeverPublished(): void
    {
        $body = MultipartBuilder::withFile(
            'package',
            'symbols.snupkg',
            Fixtures::contents('Packages/Pepite.Fixtures.Simple.1.0.0.nupkg'),
        );

        $result = $this->withHeaders([
            'X-NuGet-ApiKey' => $this->pushKey,
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/default/api/v2/symbolpackage');

        $result->assertStatus(404);
    }

    // --------------------------------------------------------- authorisation

    public function testAPushWithoutAKeyAnswers401(): void
    {
        $body = MultipartBuilder::withFile(
            'package',
            'p.nupkg',
            Fixtures::contents('Packages/Pepite.Fixtures.Simple.1.0.0.nupkg'),
        );

        $result = $this->withHeaders(['Content-Type' => MultipartBuilder::contentType()])
            ->withBody($body)
            ->call('put', 'feeds/default/api/v2/package');

        $result->assertStatus(401);
    }

    public function testAPushWithAnUnknownKeyAnswers401(): void
    {
        $body = MultipartBuilder::withFile(
            'package',
            'p.nupkg',
            Fixtures::contents('Packages/Pepite.Fixtures.Simple.1.0.0.nupkg'),
        );

        $result = $this->withHeaders([
            'X-NuGet-ApiKey' => 'not-a-real-key',
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/default/api/v2/package');

        $result->assertStatus(401);
    }

    /**
     * The whole point of scoped keys: a read-only key must not be able to
     * publish, even though it authenticates just fine.
     */
    public function testAReadOnlyKeyCannotPushAnswers403(): void
    {
        $result = $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg', key: $this->readOnlyKey);

        $result->assertStatus(403);
        $this->assertSame(0, model(PackageVersionModel::class)->countAllResults());
    }

    // -------------------------------------------------- unlist and relist

    public function testUnlistHidesFromSearchButKeepsTheBlobDownloadable(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        $result = $this->withHeaders(['X-NuGet-ApiKey' => $this->pushKey])
            ->call('delete', 'feeds/default/api/v2/package/Pepite.Fixtures.Simple/1.0.0');

        $result->assertStatus(204);

        $version = model(PackageVersionModel::class)->first();
        $this->assertSame(0, (int) $version['is_listed']);
        $this->assertTrue(service('packageStorage')->exists($version['nupkg_path']), 'unlisting must not delete the blob');
    }

    public function testRelistMakesAnUnlistedVersionVisibleAgain(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        $this->withHeaders(['X-NuGet-ApiKey' => $this->pushKey])
            ->call('delete', 'feeds/default/api/v2/package/Pepite.Fixtures.Simple/1.0.0')
            ->assertStatus(204);

        $result = $this->withHeaders(['X-NuGet-ApiKey' => $this->pushKey])
            ->call('post', 'feeds/default/api/v2/package/Pepite.Fixtures.Simple/1.0.0');

        $result->assertStatus(204);
        $this->assertSame(1, (int) model(PackageVersionModel::class)->first()['is_listed']);
    }

    public function testUnlistingAnUnknownVersionAnswers404(): void
    {
        $result = $this->withHeaders(['X-NuGet-ApiKey' => $this->pushKey])
            ->call('delete', 'feeds/default/api/v2/package/Nope.Nothing/1.0.0');

        $result->assertStatus(404);
    }

    public function testAReadOnlyKeyCannotUnlistAnswers403(): void
    {
        $this->push('Pepite.Fixtures.Simple.1.0.0.nupkg')->assertStatus(201);

        $result = $this->withHeaders(['X-NuGet-ApiKey' => $this->readOnlyKey])
            ->call('delete', 'feeds/default/api/v2/package/Pepite.Fixtures.Simple/1.0.0');

        $result->assertStatus(403);
    }

    /**
     * @return TestResponse
     */
    private function push(string $fixture, string $feed = 'default', ?string $key = null)
    {
        $body = MultipartBuilder::withFile('package', 'package.nupkg', Fixtures::contents('Packages/' . $fixture));

        return $this->withHeaders([
            'X-NuGet-ApiKey' => $key ?? $this->pushKey,
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/' . $feed . '/api/v2/package');
    }

    private function createUser(string $email): User
    {
        $users = model(UserModel::class);

        $users->save(new User([
            'username' => explode('@', $email)[0],
            'email'    => $email,
            'password' => 'pepite-test-2026',
        ]));

        return $users->findById($users->getInsertID());
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
