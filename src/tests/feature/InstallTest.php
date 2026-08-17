<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use SQLite3;

/**
 * The web installer: the only path to a working server on a host with no
 * SSH. Runs every step for real — connect, migrate, create the admin, write
 * .env, lock — against a throwaway SQLite file.
 *
 * Getting migrations to actually land there took a real fix, not a test
 * workaround: `MigrationRunner::setNamespace(null)` (spark migrate --all)
 * scans every registered namespace, including the dev-only Tests\Support
 * autoload-dev namespace — and its scaffold example migration declares
 * `protected $DBGroup = 'tests'`, which CodeIgniter\Database\Migration's
 * constructor honours before it even looks at the connection it was handed,
 * unconditionally routing that one migration to the shared "tests" group no
 * matter what this controller configures. Once that group already has a
 * "factories" table, "already exists" follows. Install.php now migrates
 * three explicit namespaces (App, Shield, Settings — the only ones a real
 * `--all` run has ever produced, per writable/pepite.db's own migrations
 * table) instead of scanning everything.
 *
 * Shield's own models still resolve their connection through
 * `db_connect(null)`, which app/Config/Database.php separately hardcodes to
 * the "tests" group whenever ENVIRONMENT === 'testing' — unrelated to the
 * bug above, and not a problem in production, where that hardcoding does
 * not exist. DatabaseTestTrait keeps that group migrated, so the admin this
 * test creates is read back through it rather than through the scratch file.
 *
 * The controller writes ROOTPATH/.env for real — that is its job — so this
 * backs it up and restores it, or running this suite would overwrite the
 * developer's own .env with the scratch SQLite path.
 *
 * @internal
 */
final class InstallTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;
    private string $dbPath;
    private string $envPath;
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath   = sys_get_temp_dir() . '/pepite-install-' . bin2hex(random_bytes(6)) . '.db';
        $this->envPath  = ROOTPATH . '.env';
        $this->lockPath = WRITEPATH . 'install.lock';

        if (is_file($this->lockPath)) {
            rename($this->lockPath, $this->lockPath . '.bak');
        }

        if (is_file($this->envPath)) {
            copy($this->envPath, $this->envPath . '.bak');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
        @unlink($this->lockPath);

        if (is_file($this->lockPath . '.bak')) {
            rename($this->lockPath . '.bak', $this->lockPath);
        }

        if (is_file($this->envPath . '.bak')) {
            rename($this->envPath . '.bak', $this->envPath);
        } else {
            @unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function testFreshInstallRunsMigrationsCreatesTheAdminAndLocks(): void
    {
        $result = $this->postWithCsrf($this->form());

        $result->assertOK();
        $this->assertFileExists($this->lockPath);

        $envContents = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('database.default.DBDriver = SQLite3', $envContents);
        $this->assertStringContainsString('database.default.database = ' . $this->dbPath, $envContents);
        $this->assertStringContainsString('encryption.key = hex2bin:', $envContents);

        // Migrations really ran against the submitted database.
        $db    = new SQLite3($this->dbPath);
        $count = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'");
        $this->assertSame(1, $count);

        // The administrator exists — see the class docblock for why this is
        // read from the "tests" group rather than the scratch file above.
        $admin = model(UserModel::class)->findByCredentials(['email' => 'admin@pepite.test']);
        $this->assertNotNull($admin);
        $this->assertTrue($admin->inGroup('admin'));
    }

    public function testASecondVisitAfterInstallIsRefused(): void
    {
        $this->postWithCsrf($this->form())->assertOK();

        $this->call('get', 'install')->assertStatus(403);
    }

    public function testMissingAdminFieldsAreRejectedBeforeTouchingTheDatabase(): void
    {
        $data = $this->form();
        unset($data['admin_email']);

        $result = $this->postWithCsrf($data);

        $result->assertStatus(400);
        $this->assertFileDoesNotExist($this->lockPath);
    }

    /**
     * @param array<string, string> $data
     */
    private function postWithCsrf(array $data)
    {
        $page  = $this->get('install')->response()->getBody();
        $token = (preg_match('/name="csrf_test_name"\s+value="([^"]+)"/', (string) $page, $m) === 1) ? $m[1] : '';

        return $this->post('install', $data + ['csrf_test_name' => $token]);
    }

    /**
     * @return array<string, string>
     */
    private function form(): array
    {
        return [
            'base_url'       => 'http://pepite.test/',
            'db_driver'      => 'SQLite3',
            'db_database'    => $this->dbPath,
            'admin_email'    => 'admin@pepite.test',
            'admin_username' => 'admin',
            'admin_password' => 'installer-test-2026',
        ];
    }
}
