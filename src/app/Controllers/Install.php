<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Config\Factories;
use CodeIgniter\Controller;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Migrations as MigrationsConfig;
use RuntimeException;
use Throwable;

/**
 * The web installer: shared hosting has no SSH, so `spark migrate` is not an
 * option. This does everything by hand, in one request — check the runtime,
 * take the database connection, run every migration, create the first
 * administrator, generate an encryption key, then lock itself out.
 *
 * The database has to be wired up through the environment, not by handing a
 * connection object around: a Migration class that does not declare its own
 * $DBGroup resolves its Forge through `Database::forge(config(Database::class)
 * ->defaultGroup)` on its own, ignoring whatever connection a MigrationRunner
 * was constructed with. So the submitted settings are applied as environment
 * variables — with Config\Database's cache dropped so they take effect on
 * this same request — before anything touches the database, and every
 * migration then resolves the "default" group correctly on its own.
 */
final class Install extends Controller
{
    private const LOCK_FILE = WRITEPATH . 'install.lock';

    public function index(): ResponseInterface
    {
        if ($this->isLocked()) {
            return $this->alreadyInstalled();
        }

        return $this->response->setBody(view('install/index', [
            'requirements' => $this->checkRequirements(),
            'baseUrl'      => (string) $this->request->getUri()->setPath('/')->setQuery('')->setFragment(''),
            'errors'       => [],
            'old'          => [],
        ]));
    }

    public function store(): ResponseInterface
    {
        if ($this->isLocked()) {
            return $this->alreadyInstalled();
        }

        $requirements = $this->checkRequirements();

        if (in_array(false, array_column($requirements, 'ok'), true)) {
            return $this->fail($requirements, [], ['Le serveur ne remplit pas les prérequis ci-dessous.']);
        }

        $post   = $this->request->getPost();
        $errors = $this->validateInput($post);

        if ($errors !== []) {
            return $this->fail($requirements, $post, $errors);
        }

        $dbConfig = $this->databaseOverrides($post);
        $this->applyEnvironment($dbConfig);

        try {
            $connection = db_connect('default', false);
            $connection->connect();
        } catch (Throwable $e) {
            return $this->fail($requirements, $post, ['Connexion à la base de données impossible : ' . $e->getMessage()]);
        }

        try {
            $runner = new MigrationRunner(new MigrationsConfig(), $connection);

            // Not setNamespace(null): that mode (spark migrate --all) also
            // scans Tests\Support, a dev-only autoload-dev namespace carrying
            // CI4's own scaffold example migration — hardcoded to the "tests"
            // group regardless of anything this controller configures, so it
            // collides the moment that group already has a "factories" table.
            // These three are what an actual `--all` run has ever produced
            // here (checked against writable/pepite.db's migrations table).
            foreach (['App', 'CodeIgniter\Shield', 'CodeIgniter\Settings'] as $namespace) {
                $runner->setNamespace($namespace);

                if (! $runner->latest()) {
                    throw new RuntimeException(implode(' ', $runner->getCliMessages()) ?: 'Échec des migrations.');
                }
            }
        } catch (Throwable $e) {
            return $this->fail($requirements, $post, ['Migrations : ' . $e->getMessage()], 500);
        }

        $this->createAdmin((string) $post['admin_email'], (string) $post['admin_username'], (string) $post['admin_password']);

        $this->writeEnv($dbConfig, (string) $post['base_url']);
        $this->lock();

        return $this->response->setBody(view('install/done'));
    }

    /**
     * @param list<array{label: string, ok: bool}> $requirements
     * @param array<string, mixed>                 $post
     * @param list<string>                         $errors
     */
    private function fail(array $requirements, array $post, array $errors, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setBody(view('install/index', [
            'requirements' => $requirements,
            'baseUrl'      => (string) ($post['base_url'] ?? $this->request->getUri()->setPath('/')->setQuery('')->setFragment('')),
            'errors'       => $errors,
            'old'          => $post,
        ]));
    }

    /**
     * @return list<array{label: string, ok: bool}>
     */
    private function checkRequirements(): array
    {
        return [
            ['label' => 'PHP 8.2 ou supérieur (actuel : ' . PHP_VERSION . ')', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'Extension intl', 'ok' => extension_loaded('intl')],
            ['label' => 'Extension mbstring', 'ok' => extension_loaded('mbstring')],
            ['label' => 'Extension zip', 'ok' => extension_loaded('zip')],
            ['label' => 'Extension dom', 'ok' => extension_loaded('dom')],
            ['label' => 'PDO MySQL ou SQLite3 disponible', 'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite')],
            ['label' => 'writable/ accessible en écriture', 'ok' => is_writable(WRITEPATH)],
            ['label' => 'Fichier .env accessible en écriture', 'ok' => $this->envIsWritable()],
        ];
    }

    private function envIsWritable(): bool
    {
        $path = ROOTPATH . '.env';

        return is_file($path) ? is_writable($path) : is_writable(ROOTPATH);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return list<string>
     */
    private function validateInput(array $post): array
    {
        $errors = [];

        if (trim((string) ($post['base_url'] ?? '')) === '') {
            $errors[] = 'L\'URL du site est requise.';
        }

        $driver = (string) ($post['db_driver'] ?? '');

        if (! in_array($driver, ['MySQLi', 'SQLite3'], true)) {
            $errors[] = 'Choisissez un moteur de base de données.';
        }

        if ($driver === 'MySQLi' && trim((string) ($post['db_database'] ?? '')) === '') {
            $errors[] = 'Le nom de la base MySQL est requis.';
        }

        if (! filter_var($post['admin_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail de l\'administrateur invalide.';
        }

        if (trim((string) ($post['admin_username'] ?? '')) === '') {
            $errors[] = 'Nom d\'utilisateur de l\'administrateur requis.';
        }

        if (strlen((string) ($post['admin_password'] ?? '')) < 8) {
            $errors[] = 'Le mot de passe administrateur doit faire au moins 8 caractères.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    private function databaseOverrides(array $post): array
    {
        if ((string) $post['db_driver'] === 'SQLite3') {
            return [
                'DBDriver' => 'SQLite3',
                'database' => trim((string) $post['db_database']) ?: 'pepite.db',
            ];
        }

        return [
            'DBDriver' => 'MySQLi',
            'hostname' => (string) $post['db_hostname'],
            'database' => (string) $post['db_database'],
            'username' => (string) $post['db_username'],
            'password' => (string) $post['db_password'],
            'port'     => (int) ($post['db_port'] ?: 3306),
        ];
    }

    /**
     * Points the "default" DB group at the submitted database for the rest of
     * this request. env() reads $_ENV / $_SERVER / getenv(), so all three are
     * set; Factories::reset('config') drops the Config\Database instance the
     * framework cached at boot, before any of this ran — without it, every
     * config(Database::class) call downstream would keep answering with
     * whatever .env said when the request started.
     *
     * @param array<string, mixed> $db
     */
    private function applyEnvironment(array $db): void
    {
        foreach ($this->databaseEnvLines($db) as $line) {
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        Factories::reset('config');
    }

    /**
     * @param array<string, mixed> $db
     *
     * @return list<string>
     */
    private function databaseEnvLines(array $db): array
    {
        $lines = ['database.default.DBDriver = ' . $db['DBDriver']];

        if ($db['DBDriver'] === 'SQLite3') {
            $lines[] = 'database.default.database = ' . $db['database'];

            return $lines;
        }

        $lines[] = 'database.default.hostname = ' . $db['hostname'];
        $lines[] = 'database.default.database = ' . $db['database'];
        $lines[] = 'database.default.username = ' . $db['username'];
        $lines[] = 'database.default.password = ' . $db['password'];
        $lines[] = 'database.default.port = ' . $db['port'];

        return $lines;
    }

    private function createAdmin(string $email, string $username, string $password): void
    {
        $users = model(UserModel::class);

        $user = new User([
            'username' => $username,
            'email'    => $email,
            'password' => $password,
        ]);

        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->addGroup('admin');
    }

    /**
     * Overwrites .env with exactly what the app needs: full replacement
     * rather than patching, since this only ever runs once on an install with
     * no meaningful .env to preserve.
     *
     * @param array<string, mixed> $db
     */
    private function writeEnv(array $db, string $baseUrl): void
    {
        $key = 'hex2bin:' . bin2hex(random_bytes(32));

        $lines = [
            'CI_ENVIRONMENT = production',
            '',
            "app.baseURL = '" . rtrim($baseUrl, '/') . "/'",
            '',
            ...$this->databaseEnvLines($db),
        ];

        $lines[] = '';
        $lines[] = 'encryption.key = ' . $key;
        $lines[] = '';

        file_put_contents(ROOTPATH . '.env', implode("\n", $lines) . "\n");
    }

    private function lock(): void
    {
        file_put_contents(self::LOCK_FILE, date('c'));
    }

    private function isLocked(): bool
    {
        return is_file(self::LOCK_FILE);
    }

    private function alreadyInstalled(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setBody(view('install/locked'));
    }
}
