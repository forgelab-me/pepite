<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Exceptions\PublishException;
use App\Libraries\Package\NupkgReader;
use App\Libraries\PackagePublisher;
use App\Libraries\PackageStorage;
use App\Libraries\PublishAuthorizer;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Fixtures;

/**
 * Who may push what — PLAN.md 9.2 steps 2 and 3, exercised directly against
 * the database rather than through HTTP: PackagePublishTest already proves
 * the plumbing from a real multipart request down to this point, so these
 * tests focus on the decision matrix itself.
 *
 * The fixtures each carry exactly one version, so "push a second version of
 * an existing package" is simulated by deleting the recorded version row
 * after the first publish: the package row survives, the version does not,
 * and re-publishing the same fixture then exercises the "package exists"
 * branch — ownership and can-create — without the 409 check masking it.
 *
 * @internal
 */
final class PublishAuthorizerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $storageRoot;
    private PackagePublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-authz-' . bin2hex(random_bytes(6));

        $this->publisher = new PackagePublisher(
            model(FeedModel::class),
            model(PackageModel::class),
            model(PackageVersionModel::class),
            model(PackageDependencyModel::class),
            model(PackageOwnerModel::class),
            new PackageStorage($this->storageRoot, 4194304),
            db_connect(),
            new PublishAuthorizer(model(FeedApiKeyRuleModel::class), model(PackageOwnerModel::class)),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);

        parent::tearDown();
    }

    // ------------------------------------------------------- unrestricted keys

    public function testAKeyWithNoRuleIsUnrestricted(): void
    {
        // identityId given, but no feed_api_key_rules row exists for it — the
        // nuget.org default for a plain key.
        $result = $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 999);

        $this->assertTrue($result->claimedNewIdentifier);
    }

    // ------------------------------------------------------------- id pattern

    public function testAMatchingGlobAllowsThePush(): void
    {
        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*');

        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);

        $this->assertSame(1, model(PackageModel::class)->countAllResults());
    }

    public function testANonMatchingGlobRefusesThePush(): void
    {
        $this->rule(identity: 1, pattern: 'Contoso.*');

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame(0, model(PackageModel::class)->countAllResults());
    }

    public function testGlobMatchingIsCaseInsensitive(): void
    {
        $this->rule(identity: 1, pattern: 'pepite.fixtures.*');

        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);

        $this->assertSame(1, model(PackageModel::class)->countAllResults());
    }

    /**
     * Any one matching rule is enough — a key may hold several patterns.
     */
    public function testAnyMatchingRuleAmongSeveralIsEnough(): void
    {
        $this->rule(identity: 1, pattern: 'Contoso.*');
        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*');

        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);

        $this->assertSame(1, model(PackageModel::class)->countAllResults());
    }

    public function testARuleScopedToAnotherFeedDoesNotApplyHere(): void
    {
        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $otherFeedId = (int) model(FeedModel::class)->findBySlug('other')['id'];

        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*', feedId: $otherFeedId);

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);
            $this->fail('expected a refusal: the rule belongs to a different feed');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testARuleWithNoFeedAppliesToEveryFeed(): void
    {
        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*', feedId: null);

        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);

        $this->assertSame(1, model(PackageModel::class)->countAllResults());
    }

    // -------------------------------------------------------------- can-create

    public function testACiKeyCannotCreateANewIdentifier(): void
    {
        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*', canCreate: false);

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertSame(0, model(PackageModel::class)->countAllResults());
    }

    public function testACiKeyCanPushANewVersionOfAPackageItAlreadyOwns(): void
    {
        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: null);
        $this->dropRecordedVersion('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $this->rule(identity: 1, pattern: 'Pepite.Fixtures.*', canCreate: false);

        // The package already exists, so canCreate is irrelevant here — only
        // the pattern and ownership matter.
        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: 1);

        $this->assertSame(1, model(PackageVersionModel::class)->countAllResults());
    }

    // --------------------------------------------------------------- ownership

    public function testTheOwnerCanPushASecondVersionOfTheirOwnPackage(): void
    {
        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: null);
        $this->dropRecordedVersion('Pepite.Fixtures.Simple.1.0.0.nupkg');

        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: null);

        $this->assertSame(1, model(PackageVersionModel::class)->countAllResults());
    }

    public function testANonOwnerIsRefusedEvenWithAnUnrestrictedKey(): void
    {
        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: null);
        $packageId = $this->dropRecordedVersion('Pepite.Fixtures.Simple.1.0.0.nupkg');

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 2, identity: 5);
            $this->fail('a non-owner must not be able to publish into an existing package');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }

        $owners = model(PackageOwnerModel::class);
        $this->assertTrue($owners->owns($packageId, 1));
        $this->assertFalse($owners->owns($packageId, 2));
    }

    public function testAKeyWithNoAuthenticatedUserIsRefusedOnAnExistingPackage(): void
    {
        $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: 1, identity: null);
        $this->dropRecordedVersion('Pepite.Fixtures.Simple.1.0.0.nupkg');

        try {
            $this->publish('Pepite.Fixtures.Simple.1.0.0.nupkg', owner: null, identity: 7);
            $this->fail('expected a refusal');
        } catch (PublishException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    private function rule(
        int $identity,
        ?string $pattern = null,
        ?int $feedId = null,
        bool $canCreate = true,
    ): void {
        model(FeedApiKeyRuleModel::class)->insert([
            'identity_id'        => $identity,
            'feed_id'            => $feedId,
            'id_pattern'         => $pattern,
            'can_create_package' => $canCreate,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    private function publish(string $fixture, ?int $owner, ?int $identity)
    {
        return $this->publisher->publish(Fixtures::package($fixture), 'default', $owner, $identity);
    }

    /**
     * Deletes the version row of a just-published fixture, leaving the
     * package row in place, and returns that package's row id.
     */
    private function dropRecordedVersion(string $fixture): int
    {
        $reader  = NupkgReader::open(Fixtures::package($fixture));
        $idLower = $reader->metadata()->idLower();
        $reader->close();

        $package = model(PackageModel::class)->where('package_id_lower', $idLower)->first();
        model(PackageVersionModel::class)->where('package_id', (int) $package['id'])->delete();

        return (int) $package['id'];
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
