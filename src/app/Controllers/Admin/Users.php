<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Validation\ValidationRules;

/**
 * Admin accounts, managed from the console instead of the CLI — the whole
 * point of Pépite is a shared host with no SSH, so `php spark shield:user`
 * cannot be the only way to add a second admin.
 *
 * Deliberately narrow: this manages membership in the 'admin' group only,
 * not Shield's full group/permission system (superadmin, developer, beta —
 * none of which the app checks anywhere). Removing admin access demotes an
 * account rather than deleting it: a Shield user backs package ownership
 * and audit trails elsewhere, and demoting is the reversible half of that —
 * the same "never destroy, only change visibility" instinct as delisting a
 * package instead of removing it.
 */
final class Users extends Controller
{
    public function index(): ResponseInterface
    {
        return $this->response->setBody(view('admin/users/index', [
            'admins'        => $this->admins(),
            'currentUserId' => (int) auth()->id(),
        ]));
    }

    public function create(): ResponseInterface
    {
        return $this->response->setBody(view('admin/users/create', ['errors' => []]));
    }

    /**
     * UserModel::save() enforces none of this by itself — username/email
     * uniqueness and password strength are only checked by whichever caller
     * runs Shield's own registration validation rules, which is otherwise
     * just RegisterController. Reusing that same rule set here is what turns
     * a weak password or a colliding username/email into a clean error
     * instead of a raw database exception.
     */
    public function store(): ResponseInterface
    {
        $email    = trim((string) $this->request->getPost('email'));
        $username = trim((string) $this->request->getPost('username')) ?: $this->usernameFrom($email);

        $data = [
            'username'         => $username,
            'email'            => $email,
            'password'         => (string) $this->request->getPost('password'),
            'password_confirm' => (string) $this->request->getPost('password_confirm'),
        ];

        $rules = (new ValidationRules())->getRegistrationRules();

        if (! $this->validateData($data, $rules, [], config('Auth')->DBGroup)) {
            return $this->response->setBody(view('admin/users/create', ['errors' => $this->validator->getErrors()]));
        }

        $users = model(UserModel::class);
        $user  = new User(['username' => $username, 'email' => $email, 'password' => $data['password']]);
        $users->save($user);

        $user = $users->findById($users->getInsertID());
        $user->addGroup('admin');
        $user->activate();

        return redirect()->to(site_url('admin/users'))->with('message', sprintf('Admin account "%s" created.', $email));
    }

    public function destroy(int $userId): ResponseInterface
    {
        if ($userId === (int) auth()->id()) {
            return redirect()->to(site_url('admin/users'))->with('error', 'You cannot remove your own admin access.');
        }

        if (count($this->admins()) <= 1) {
            return redirect()->to(site_url('admin/users'))->with('error', 'At least one admin account must remain.');
        }

        $users = model(UserModel::class);
        $user  = $users->findById($userId);

        if ($user === null || ! $user->inGroup('admin')) {
            return redirect()->to(site_url('admin/users'))->with('error', 'No such admin account.');
        }

        $user->removeGroup('admin');

        return redirect()->to(site_url('admin/users'))->with('message', 'Admin access removed.');
    }

    /**
     * Shield keeps the address on the `email_password` identity, not on
     * `users` itself — findByCredentials() joins the same way for the same
     * reason.
     *
     * @return list<array<string, mixed>>
     */
    private function admins(): array
    {
        return db_connect()->table('users')
            ->select('users.id, users.username, users.active, auth_identities.secret as email')
            ->join('auth_groups_users', 'auth_groups_users.user_id = users.id')
            ->join('auth_identities', "auth_identities.user_id = users.id AND auth_identities.type = 'email_password'")
            ->where('auth_groups_users.group', 'admin')
            ->orderBy('users.username', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function usernameFrom(string $email): string
    {
        $local    = strstr($email, '@', true) ?: 'admin';
        $username = preg_replace('/[^a-zA-Z0-9.]/', '', $local);

        return $username === '' || $username === null ? 'admin' : $username;
    }
}
