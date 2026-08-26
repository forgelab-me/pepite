<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The admin console side of Trusted Publishing: adding, listing and removing
 * the repos a feed trusts. PublishTokenTest covers what happens once one is
 * configured; this covers configuring it.
 *
 * @internal
 */
final class PublishersAdminTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private User $admin;
    private int $feedId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = model(UserModel::class);
        $users->save(new User(['username' => 'admin', 'email' => 'admin@pepite.test', 'password' => 'pepite-test-2026']));
        $this->admin = $users->findById($users->getInsertID());
        $this->admin->addGroup('admin');

        model(FeedModel::class)->insert(['slug' => 'contoso', 'name' => 'Contoso']);
        $this->feedId = (int) model(FeedModel::class)->getInsertID();
    }

    public function testAGuestIsRedirectedToLogin(): void
    {
        $this->call('get', 'admin/feeds/' . $this->feedId . '/publishers')->assertRedirectTo(route_to('login'));
    }

    public function testAnAdminCanTrustARepository(): void
    {
        $result = $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'environment'         => 'release',
            'id_pattern'          => 'Contoso.*',
            'can_create_package'  => '1',
        ]);

        $result->assertRedirect();

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertSame('forgelab-me/pepite', $publisher['repository']);
        $this->assertSame('10387667', $publisher['repository_owner_id']);
        $this->assertSame('release', $publisher['environment']);
        $this->assertSame('Contoso.*', $publisher['id_pattern']);
        $this->assertSame(1, (int) $publisher['can_create_package']);
        $this->assertSame((int) $this->admin->id, (int) $publisher['user_id']);
    }

    public function testBlankOptionalFieldsAreStoredAsNull(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
        ]);

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertNull($publisher['environment']);
        $this->assertNull($publisher['id_pattern']);
        $this->assertSame(0, (int) $publisher['can_create_package']);
    }

    public function testAnAdminCanTrustAGitlabGroupWithNestedSubgroups(): void
    {
        $result = $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'provider'            => 'gitlab',
            'repository'          => 'forgelab-me/tools/pepite-mirror',
            'repository_owner_id' => '555',
            'workflow'            => 'forgelab-me/tools/pepite-mirror//.gitlab-ci.yml',
        ]);

        $result->assertRedirect();

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertSame('gitlab', $publisher['provider']);
        $this->assertSame('forgelab-me/tools/pepite-mirror', $publisher['repository']);
        $this->assertSame('forgelab-me/tools/pepite-mirror//.gitlab-ci.yml', $publisher['workflow']);
    }

    public function testOmittingTheProviderFieldDefaultsToGithub(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
        ]);

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertSame('github', $publisher['provider']);
    }

    public function testAnUnknownProviderIsRejected(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'provider'            => 'bitbucket',
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
        ]);

        $this->assertSame([], model(TrustedPublisherModel::class)->forFeed($this->feedId));
    }

    public function testBlankWorkflowIsStoredAsNull(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
        ]);

        $publisher = model(TrustedPublisherModel::class)->forFeed($this->feedId)[0];
        $this->assertNull($publisher['workflow']);
    }

    public function testARepositoryNotShapedLikeOwnerSlashNameIsRejected(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'not-a-valid-repo',
            'repository_owner_id' => '10387667',
        ]);

        $this->assertSame([], model(TrustedPublisherModel::class)->forFeed($this->feedId));
    }

    public function testANonNumericOwnerIdIsRejected(): void
    {
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers', [
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => 'forgelab-me',
        ]);

        $this->assertSame([], model(TrustedPublisherModel::class)->forFeed($this->feedId));
    }

    public function testAnAdminCanRemoveATrustedPublisher(): void
    {
        model(TrustedPublisherModel::class)->insert([
            'feed_id'             => $this->feedId,
            'user_id'             => (int) $this->admin->id,
            'provider'            => 'github',
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'environment'         => null,
            'id_pattern'          => null,
            'can_create_package'  => false,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
        $publisherId = (int) model(TrustedPublisherModel::class)->getInsertID();

        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers/' . $publisherId . '/delete', [])
            ->assertRedirect();

        $this->assertSame([], model(TrustedPublisherModel::class)->forFeed($this->feedId));
    }

    public function testDeletingAPublisherOnAnotherFeedIsANoop(): void
    {
        model(FeedModel::class)->insert(['slug' => 'other', 'name' => 'Other']);
        $otherFeedId = (int) model(FeedModel::class)->getInsertID();

        model(TrustedPublisherModel::class)->insert([
            'feed_id'             => $otherFeedId,
            'user_id'             => (int) $this->admin->id,
            'provider'            => 'github',
            'repository'          => 'forgelab-me/pepite',
            'repository_owner_id' => '10387667',
            'environment'         => null,
            'id_pattern'          => null,
            'can_create_package'  => false,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
        $publisherId = (int) model(TrustedPublisherModel::class)->getInsertID();

        // Crafted id: trying to delete a row that belongs to a different feed
        // through this feed's own delete route must not touch it.
        $this->postWithCsrf('admin/feeds/' . $this->feedId . '/publishers/' . $publisherId . '/delete', []);

        $this->assertCount(1, model(TrustedPublisherModel::class)->forFeed($otherFeedId));
    }

    /**
     * @param array<string, string> $data
     */
    private function postWithCsrf(string $action, array $data)
    {
        $this->actingAs($this->admin);

        $page  = $this->get('admin/feeds/' . $this->feedId . '/publishers')->response()->getBody();
        $token = (preg_match('/name="csrf_test_name"\s+value="([^"]+)"/', (string) $page, $m) === 1) ? $m[1] : '';

        return $this->post($action, $data + ['csrf_test_name' => $token]);
    }
}
