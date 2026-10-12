<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Libraries\ApiKeyIssuer;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;

final class ApiKeys extends Controller
{
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $keys = $db->table('auth_identities')
            ->select('auth_identities.id, auth_identities.name, auth_identities.last_used_at, users.username, users.id as user_id')
            ->join('users', 'users.id = auth_identities.user_id')
            ->where('auth_identities.type', 'access_token')
            ->orderBy('auth_identities.id', 'DESC')
            ->get()
            ->getResultArray();

        $rules      = model(FeedApiKeyRuleModel::class)->findAll();
        $byIdentity = [];

        foreach ($rules as $rule) {
            $byIdentity[(int) $rule['identity_id']][] = $rule;
        }

        return $this->response->setBody(view('admin/keys/index', ['keys' => $keys, 'rules' => $byIdentity]));
    }

    public function create(): ResponseInterface
    {
        return $this->response->setBody(view('admin/keys/create', [
            'errors' => [],
            'feeds'  => model(FeedModel::class)->orderBy('slug', 'ASC')->findAll(),
        ]));
    }

    public function store(): ResponseInterface
    {
        $email = trim((string) $this->request->getPost('email'));
        $user  = model(UserModel::class)->findByCredentials(['email' => $email]);

        if ($user === null) {
            return $this->response->setBody(view('admin/keys/create', [
                'errors' => ['email' => 'No account with that e-mail.'],
                'feeds'  => model(FeedModel::class)->orderBy('slug', 'ASC')->findAll(),
            ]));
        }

        $name  = trim((string) $this->request->getPost('name')) ?: 'API key';
        $token = service('apiKeyIssuer')->issue(
            $user,
            $name,
            ApiKeyIssuer::scopes((bool) $this->request->getPost('read_only')),
            $this->ruleFromPost(),
        );

        return $this->response->setBody(view('admin/keys/created', ['token' => $token->raw_token]));
    }

    public function revoke(int $identityId): ResponseInterface
    {
        service('apiKeyIssuer')->revoke($identityId);

        return redirect()->to(site_url('admin/keys'))->with('message', 'Key revoked.');
    }

    public function edit(int $identityId): ResponseInterface
    {
        $identity = $this->requireIdentity($identityId);
        $rule     = model(FeedApiKeyRuleModel::class)->where('identity_id', $identityId)->first();

        return $this->response->setBody(view('admin/keys/edit', [
            'identity' => $identity,
            'rule'     => $rule,
            'scopes'   => unserialize($identity['extra']),
            'feeds'    => model(FeedModel::class)->orderBy('slug', 'ASC')->findAll(),
        ]));
    }

    /**
     * Every field here is a full replacement, matching how the key was
     * created: the read-only toggle rewrites the scope list, and the
     * restriction fields rewrite (or remove) the single feed_api_key_rules
     * row rather than trying to reconcile a diff.
     */
    public function update(int $identityId): ResponseInterface
    {
        $this->requireIdentity($identityId);

        service('apiKeyIssuer')->reconfigure(
            $identityId,
            ApiKeyIssuer::scopes((bool) $this->request->getPost('read_only')),
            $this->ruleFromPost(),
        );

        return redirect()->to(site_url('admin/keys'))->with('message', 'Key updated.');
    }

    /**
     * The restriction the form asks for, or null when it asks for none —
     * which makes the key unrestricted, so an admin leaving every field
     * blank is a deliberate act, not a default.
     *
     * @return array{feed_id: int|null, id_pattern: string|null, can_create_package: bool}|null
     */
    private function ruleFromPost(): ?array
    {
        $feedSlug = trim((string) $this->request->getPost('feed'));
        $pattern  = trim((string) $this->request->getPost('pattern'));
        $noCreate = (bool) $this->request->getPost('no_create');
        $feed     = $feedSlug === '' ? null : model(FeedModel::class)->findBySlug($feedSlug);

        if ($feed === null && $pattern === '' && ! $noCreate) {
            return null;
        }

        return [
            'feed_id'            => $feed === null ? null : (int) $feed['id'],
            'id_pattern'         => $pattern === '' ? null : $pattern,
            'can_create_package' => ! $noCreate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireIdentity(int $identityId): array
    {
        $identity = model(UserIdentityModel::class)->asArray()
            ->where('id', $identityId)
            ->where('type', 'access_token')
            ->first();

        if ($identity === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $identity;
    }
}
