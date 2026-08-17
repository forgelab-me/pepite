<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Lot 0 acceptance test: Shield is wired up and the self-update panel is
 * reachable by an administrator, and by nobody else.
 *
 * @internal
 */
final class AdminAccessTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace; // every namespace, so Shield's tables exist

    public function testUpdatePanelRedirectsGuestsToLogin(): void
    {
        $result = $this->get('admin/updates');

        $result->assertRedirect();
        $this->assertStringContainsString('login', $result->getRedirectUrl());
    }

    public function testUpdatePanelRejectsNonAdministrators(): void
    {
        $user = $this->createUser('member@pepite.test');
        $user->addGroup('user');

        $result = $this->actingAs($user)->get('admin/updates');

        // Shield answers a denied group with a redirect, not a 403 — assert on
        // the destination, because TestResponse::isOK() accepts 3xx as valid.
        $result->assertRedirect();
        $this->assertStringNotContainsString('admin/updates', $result->getRedirectUrl());
    }

    public function testUpdatePanelIsReachableByAnAdministrator(): void
    {
        $user = $this->createUser('boss@pepite.test');
        $user->addGroup('admin');

        $result = $this->actingAs($user)->get('admin/updates');

        $result->assertOK();
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
}
