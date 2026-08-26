<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\PackageStorage;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FakeGithubOidc;
use Tests\Support\FakeGitlabOidc;
use Tests\Support\Fixtures;
use Tests\Support\Fixtures\Http\MultipartBuilder;

/**
 * Trusted Publishing end to end: a GitHub Actions OIDC token comes in, a
 * scoped NuGet API key goes out — the same credential an admin could issue
 * by hand, which is what PublishTokenTest proves by actually pushing with it.
 *
 * @internal
 */
final class PublishTokenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private int $feedId;
    private User $trustingAdmin;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGithubOidc::seedCache();
        FakeGitlabOidc::seedCache();

        $this->storageRoot = sys_get_temp_dir() . '/pepite-trusted-publisher-' . bin2hex(random_bytes(6));
        Services::injectMock('packageStorage', new PackageStorage($this->storageRoot, 4194304));
        Services::resetSingle('feedResolver');

        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $this->feedId = (int) model(FeedModel::class)->getInsertID();

        $users = model(UserModel::class);
        $users->save(new User(['username' => 'admin', 'email' => 'admin@pepite.test', 'password' => 'pepite-test-2026']));
        $this->trustingAdmin = $users->findById($users->getInsertID());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        Services::cache()->clean();
        Services::reset();
        parent::tearDown();
    }

    public function testMintingAScopedTokenAndPushingWithIt(): void
    {
        $this->trust(['id_pattern' => 'Pepite.Fixtures.*', 'can_create_package' => true]);

        $result = $this->mint($this->validToken());

        $result->assertStatus(201);
        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertNotEmpty($json['token']);
        $this->assertSame(900, $json['expires_in']);
        $this->assertStringContainsString('packages.push', $json['scope']);

        $rule = model(FeedApiKeyRuleModel::class)->where('feed_id', $this->feedId)->first();
        $this->assertNotNull($rule);
        $this->assertSame('Pepite.Fixtures.*', $rule['id_pattern']);
        $this->assertSame(1, (int) $rule['can_create_package']);

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertNotNull($publisher['last_used_at']);

        // The minted token is a real Shield access token: pushing with it
        // has to work exactly like a manually issued key. The name comes
        // from Identity::describe() in forgelab-me/ci4-trusted-publishing.
        $identity = model(UserIdentityModel::class)->asArray()->where('name', 'ci: forgelab-me/pepite (github)')->first();
        $this->assertNotNull($identity);
        $this->assertSame((int) $this->trustingAdmin->id, (int) $identity['user_id']);

        $body = MultipartBuilder::withFile('package', 'package.nupkg', Fixtures::contents('Packages/Pepite.Fixtures.Simple.1.0.0.nupkg'));

        $this->withHeaders([
            'X-NuGet-ApiKey' => $json['token'],
            'Content-Type'   => MultipartBuilder::contentType(),
        ])->withBody($body)->call('put', 'feeds/contoso/api/v2/package')->assertStatus(201);
    }

    public function testAnUnknownFeedIs404(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->validToken()])
            ->call('post', 'feeds/nope/api/v2/publish/token')
            ->assertStatus(404);
    }

    public function testAMissingBearerTokenIs401(): void
    {
        $this->call('post', 'feeds/contoso/api/v2/publish/token')->assertStatus(401);
    }

    public function testAnInvalidJwtIs401(): void
    {
        $this->mint('not-a-real-jwt')->assertStatus(401);
    }

    public function testANonMatchingRepositoryIs403(): void
    {
        $this->trust(['repository' => 'forgelab-me/pepite']);

        $token = FakeGithubOidc::token([
            'aud'                 => $this->audience(),
            'repository'          => 'someone-else/unrelated',
            'repository_owner_id' => '10387667',
        ]);

        $this->mint($token)->assertStatus(403);
    }

    public function testAnEnvironmentMismatchIs403(): void
    {
        $this->trust(['environment' => 'release']);

        $token = FakeGithubOidc::token([
            'aud'                 => $this->audience(),
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'environment'         => 'staging',
        ]);

        $this->mint($token)->assertStatus(403);
    }

    public function testADeletedTrustingAccountIs403(): void
    {
        $this->trust([]);
        model(UserModel::class)->delete($this->trustingAdmin->id, true);

        $this->mint($this->validToken())->assertStatus(403);
    }

    public function testMintingDoesNotGrantUnlist(): void
    {
        $this->trust([]);

        $result = $this->mint($this->validToken());
        $json   = json_decode((string) $result->response()->getBody(), true);

        $this->assertStringNotContainsString('packages.unlist', $json['scope']);
    }

    // ------------------------------------------------------------- gitlab

    /**
     * Nothing in the request names the provider — PublishToken has to try
     * each enabled one and find GitLab on its own.
     */
    public function testMintingAScopedTokenFromGitlab(): void
    {
        $this->trust([
            'provider'            => 'gitlab',
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '555',
        ]);

        $token = FakeGitlabOidc::token([
            'aud'          => $this->audience(),
            'project_path' => 'forgelab-me/pepite',
            'namespace_id' => '555',
        ]);

        $result = $this->mint($token);

        $result->assertStatus(201);

        $identity = model(UserIdentityModel::class)->asArray()->where('name', 'ci: forgelab-me/pepite (gitlab)')->first();
        $this->assertNotNull($identity);
    }

    /**
     * A row scoped to GitHub must not be satisfied by a GitLab token that
     * happens to carry the same repository string and owner id —
     * PublisherMatcher checks the provider column too.
     */
    public function testAGithubPublisherDoesNotMatchAGitlabToken(): void
    {
        $this->trust(['provider' => 'github', 'repository_owner_id' => '555']);

        $token = FakeGitlabOidc::token([
            'aud'          => $this->audience(),
            'project_path' => 'forgelab-me/pepite',
            'namespace_id' => '555',
        ]);

        $this->mint($token)->assertStatus(403);
    }

    // ------------------------------------------------------------ workflow

    public function testAWorkflowPinRefusesAnyOtherWorkflowInTheSameRepository(): void
    {
        $this->trust(['workflow' => 'forgelab-me/pepite/.github/workflows/release.yml']);

        $token = FakeGithubOidc::token([
            'aud'                 => $this->audience(),
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'job_workflow_ref'    => 'forgelab-me/pepite/.github/workflows/ci.yml@refs/heads/main',
        ]);

        $this->mint($token)->assertStatus(403);
    }

    public function testAWorkflowPinAcceptsTheMatchingWorkflowRegardlessOfTheTriggeringRef(): void
    {
        $this->trust(['workflow' => 'forgelab-me/pepite/.github/workflows/release.yml']);

        $token = FakeGithubOidc::token([
            'aud'                 => $this->audience(),
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            // A tag-triggered run, not main — the workflow pin does not care
            // which ref triggered it, only which file ran.
            'job_workflow_ref' => 'forgelab-me/pepite/.github/workflows/release.yml@refs/tags/v1.2.0',
        ]);

        $this->mint($token)->assertStatus(201);
    }

    /**
     * Mirrors App\Controllers\Api\PublishToken::audience(): base_url() here
     * comes from whatever the environment actually has app.baseURL set to
     * (a local .env can override the phpunit.dist.xml default), so the
     * audience embedded in a fake token has to be computed the same way
     * the controller computes the one it checks against, not hardcoded.
     */
    private function audience(): string
    {
        return rtrim(base_url(), '/');
    }

    private function validToken(): string
    {
        return FakeGithubOidc::token([
            'aud'                 => $this->audience(),
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
        ]);
    }

    private function mint(string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->call('post', 'feeds/contoso/api/v2/publish/token');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function trust(array $overrides): void
    {
        model(TrustedPublisherModel::class)->insert($overrides + [
            'feed_id'             => $this->feedId,
            'user_id'             => (int) $this->trustingAdmin->id,
            'provider'            => 'github',
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'environment'         => null,
            'id_pattern'          => null,
            'can_create_package'  => false,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
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
