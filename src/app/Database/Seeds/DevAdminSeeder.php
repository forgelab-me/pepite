<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Creates the local development administrator.
 *
 * Development convenience only — production accounts are created by the web
 * installer (lot 8), which asks for the credentials instead of shipping them.
 * Refuses to run outside the development environment for that reason.
 *
 * Password comes from the PEPITE_DEV_ADMIN_PASSWORD environment variable when
 * set, so the default below never has to be used on a machine that matters.
 */
class DevAdminSeeder extends Seeder
{
    // `.test` is the reserved TLD for local use. `admin@localhost` would be
    // rejected: Shield's valid_email rule requires a dotted domain.
    private const DEFAULT_EMAIL    = 'admin@pepite.test';
    private const DEFAULT_USERNAME = 'admin';
    private const DEFAULT_PASSWORD = 'pepite-dev-2026';

    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            echo "Refused: DevAdminSeeder must not run in production.\n";

            return;
        }

        $users = model(UserModel::class);
        $email = env('PEPITE_DEV_ADMIN_EMAIL', self::DEFAULT_EMAIL);

        if ($users->findByCredentials(['email' => $email]) !== null) {
            echo "Administrator {$email} already exists, nothing to do.\n";

            return;
        }

        $password = env('PEPITE_DEV_ADMIN_PASSWORD', self::DEFAULT_PASSWORD);

        $user = new User([
            'username' => env('PEPITE_DEV_ADMIN_USERNAME', self::DEFAULT_USERNAME),
            'email'    => $email,
            'password' => $password,
        ]);

        if (! $users->save($user)) {
            echo "Could not create the administrator:\n";

            foreach ($users->errors() as $error) {
                echo "  - {$error}\n";
            }

            return;
        }

        $user = $users->findById($users->getInsertID());
        $user->addGroup('admin');

        echo "Administrator created: {$email}\n";
        echo "Password: {$password}\n";
    }
}
