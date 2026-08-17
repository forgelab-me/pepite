<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use RuntimeException;

/**
 * Creates (or re-activates) the first administrator from ADMIN_EMAIL and
 * ADMIN_PASSWORD / ADMIN_PASSWORD_HASH — meant for environments with no web
 * installer in reach, namely the Docker image, which runs this on every
 * start instead. A source checkout on shared hosting still goes through
 * /install (see [[app/Controllers/Install.php]]), which asks for the
 * credentials interactively rather than shipping them in the environment.
 *
 * Unlike DevAdminSeeder, this is allowed in production — that is the whole
 * point of it — and it is idempotent: running it again re-activates the
 * account and re-syncs its password, so it doubles as the recovery path for
 * an admin who is locked out.
 *
 * Usage:
 *   php spark db:seed AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL', ''));
        $hash  = trim((string) env('ADMIN_PASSWORD_HASH', ''));
        $plain = (string) env('ADMIN_PASSWORD', '');

        if ($email === '' || ($hash === '' && $plain === '')) {
            throw new RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD (or ADMIN_PASSWORD_HASH) must be set before seeding the '
                . 'admin account. Generate a hash with: '
                . 'php -r "echo password_hash(\'yourpassword\', PASSWORD_DEFAULT) . PHP_EOL;"',
            );
        }

        $users    = model(UserModel::class);
        $existing = $users->findByCredentials(['email' => $email]);

        if ($existing === null) {
            $user = new User(array_filter([
                'username'      => $this->usernameFrom($email),
                'email'         => $email,
                'password'      => $hash === '' ? $plain : null,
                'password_hash' => $hash !== '' ? $hash : null,
            ], static fn ($value): bool => $value !== null));

            if (! $users->save($user)) {
                throw new RuntimeException('Could not create the admin account: ' . implode(' ', $users->errors()));
            }

            $user = $users->findById($users->getInsertID());
            $user->addGroup('admin');
            $user->activate();

            echo "Admin account created: {$email}\n";

            return;
        }

        if ($hash !== '') {
            $existing->password_hash = $hash;
        } else {
            $existing->password = $plain;
        }

        if (! $users->save($existing)) {
            throw new RuntimeException('Could not update the admin account: ' . implode(' ', $users->errors()));
        }

        $existing->activate();

        if (! $existing->inGroup('admin')) {
            $existing->addGroup('admin');
        }

        echo "Admin account updated (active, password synced): {$email}\n";
    }

    private function usernameFrom(string $email): string
    {
        $local    = strstr($email, '@', true) ?: 'admin';
        $username = preg_replace('/[^a-zA-Z0-9.]/', '', $local);

        return $username === '' || $username === null ? 'admin' : $username;
    }
}
