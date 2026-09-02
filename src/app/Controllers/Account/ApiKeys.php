<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Filters\NuGetApiKey;
use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * Self-service API keys — the third-party counterpart to Admin\ApiKeys,
 * which an admin uses to mint a key on someone else's behalf. Here the
 * recipient is always the logged-in user, and the scope is deliberately
 * narrower: packages.push + packages.unlist, never packages.read.
 *
 * packages.read is withheld on purpose. App\Filters\FeedRead authorizes a
 * private feed purely on that scope string, with no awareness of
 * feed_api_key_rules — so a key carrying it can read every private feed on
 * the instance, not just the one this form scopes a push to. An admin
 * minting a key already implicitly vets the recipient; self-service has no
 * such guarantee, so the scope that would matter is simply never issued.
 * Nothing needs it: dotnet nuget push never sends it, and a public feed
 * needs no authentication to read at all.
 */
final class ApiKeys extends Controller
{
    public function index(): ResponseInterface
    {
        $userId = (int) auth()->id();

        $keys = db_connect()->table('auth_identities')
            ->select('id, name, last_used_at')
            ->where('type', 'access_token')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $identityIds = array_column($keys, 'id');
        $rules       = $identityIds === [] ? [] : model(FeedApiKeyRuleModel::class)->whereIn('identity_id', $identityIds)->findAll();
        $feedNames   = array_column(model(FeedModel::class)->findAll(), 'name', 'id');

        $byIdentity = [];

        foreach ($rules as $rule) {
            $rule['feed_name']                        = $feedNames[(int) $rule['feed_id']] ?? 'unknown feed';
            $byIdentity[(int) $rule['identity_id']][] = $rule;
        }

        return $this->response->setBody(view('account/keys/index', ['keys' => $keys, 'rules' => $byIdentity]));
    }

    public function create(): ResponseInterface
    {
        return $this->response->setBody(view('account/keys/create', [
            'errors' => [],
            'feeds'  => $this->claimableFeeds(),
        ]));
    }

    public function store(): ResponseInterface
    {
        $feed = $this->findClaimableFeed(trim((string) $this->request->getPost('feed')));

        if ($feed === null) {
            return $this->response->setBody(view('account/keys/create', [
                'errors' => ['Pick a feed that accepts new packages from outside contributors.'],
                'feeds'  => $this->claimableFeeds(),
            ]));
        }

        $name  = trim((string) $this->request->getPost('name')) ?: 'API key';
        $token = auth()->user()->generateAccessToken($name, ['packages.push', NuGetApiKey::SCOPE_UNLIST]);

        model(FeedApiKeyRuleModel::class)->insert([
            'identity_id'        => (int) $token->id,
            'feed_id'            => (int) $feed['id'],
            'id_pattern'         => null,
            'can_create_package' => true,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setBody(view('account/keys/created', ['token' => $token->raw_token]));
    }

    public function revoke(int $identityId): ResponseInterface
    {
        $identity = model(UserIdentityModel::class)->asArray()
            ->where('id', $identityId)
            ->where('user_id', auth()->id())
            ->where('type', 'access_token')
            ->first();

        if ($identity === null) {
            return redirect()->to(site_url('account/keys'))->with('error', 'No such key.');
        }

        model(UserIdentityModel::class)->delete($identityId);
        model(FeedApiKeyRuleModel::class)->where('identity_id', $identityId)->delete();

        return redirect()->to(site_url('account/keys'))->with('message', 'Key revoked.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimableFeeds(): array
    {
        return model(FeedModel::class)
            ->where('visibility', 'public')
            ->where('allow_new_packages', true)
            ->orderBy('slug', 'ASC')
            ->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findClaimableFeed(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        return model(FeedModel::class)
            ->where('slug', strtolower($slug))
            ->where('visibility', 'public')
            ->where('allow_new_packages', true)
            ->first();
    }
}
