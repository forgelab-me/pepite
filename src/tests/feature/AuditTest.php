<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Audit;
use App\Models\AuditLogModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\LogsOut;

/**
 * The audit log: that the console's mutations leave an entry naming who did
 * them, that only an admin can read it, and that a log which cannot be
 * written never takes the action down with it.
 *
 * @internal
 */
final class AuditTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use LogsOut;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('boss@pepite.test', ['admin']);
    }

    protected function tearDown(): void
    {
        // A trigger outlives the table's rows and would trip the next refresh.
        db_connect()->query('DROP TRIGGER IF EXISTS refuse_audit');

        parent::tearDown();
    }

    public function testAGuestIsRedirectedToLogin(): void
    {
        $this->call('get', 'admin/audit')->assertRedirectTo(route_to('login'));
    }

    public function testANonAdminCannotReadTheLog(): void
    {
        $result = $this->actingAs($this->user('plain@pepite.test', []))->get('admin/audit');

        $this->assertNotSame(200, $result->response()->getStatusCode());
    }

    public function testCreatingAFeedFromTheConsoleIsRecordedWithItsAuthor(): void
    {
        $this->postWithCsrf('admin/feeds/create', 'admin/feeds', ['slug' => 'contoso', 'name' => 'Contoso']);

        $entry = model(AuditLogModel::class)->where('action', 'feed.create')->first();

        $this->assertNotNull($entry);
        $this->assertSame('contoso', $entry['target']);
        $this->assertSame('web', $entry['via']);
        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertSame('boss', $entry['actor_label']);
    }

    public function testTheAuditPageListsEntriesNewestFirst(): void
    {
        service('audit')->record('feed.create', 'older-feed');
        service('audit')->record('feed.delete', 'newer-feed');

        $body = (string) $this->actingAs($this->admin)->get('admin/audit')->response()->getBody();

        $this->assertStringContainsString('older-feed', $body);
        $this->assertLessThan(strpos($body, 'older-feed'), strpos($body, 'newer-feed'));
    }

    public function testPagingOffersOlderEntriesOnlyWhenThereAreSome(): void
    {
        for ($i = 0; $i < 51; $i++) {
            service('audit')->record('feed.update', 'feed-' . $i);
        }

        $first  = (string) $this->actingAs($this->admin)->get('admin/audit')->response()->getBody();
        $second = (string) $this->actingAs($this->admin)->get('admin/audit?page=2')->response()->getBody();

        $this->assertStringContainsString('page=2', $first);
        $this->assertStringContainsString('feed-0', $second);
        $this->assertStringNotContainsString('page=3', $second);
    }

    public function testALogThatCannotBeWrittenDoesNotThrow(): void
    {
        db_connect()->query('CREATE TRIGGER refuse_audit BEFORE INSERT ON ' . db_connect()->prefixTable('audit_log') . " BEGIN SELECT RAISE(ABORT, 'refused'); END");

        (new Audit(model(AuditLogModel::class)))->record('feed.create', 'contoso');

        $this->assertSame(0, model(AuditLogModel::class)->countAllResults());
    }

    /**
     * @param list<string> $groups
     */
    private function user(string $email, array $groups): User
    {
        $users = model(UserModel::class);
        $users->save(new User(['username' => explode('@', $email)[0], 'email' => $email, 'password' => 'pepite-test-2026']));
        $user = $users->findById($users->getInsertID());

        foreach ($groups as $group) {
            $user->addGroup($group);
        }

        return $user;
    }

    /**
     * @param array<string, string> $data
     */
    private function postWithCsrf(string $formPage, string $action, array $data)
    {
        $this->actingAs($this->admin);

        $page  = $this->get($formPage)->response()->getBody();
        $token = (preg_match('/name="csrf_test_name"\s+value="([^"]+)"/', (string) $page, $m) === 1) ? $m[1] : '';

        return $this->post($action, $data + ['csrf_test_name' => $token]);
    }
}
