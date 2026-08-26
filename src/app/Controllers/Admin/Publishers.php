<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FeedModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\TrustedPublishing;

/**
 * Trusted Publishing admin: which CI workflows may exchange their OIDC
 * identity for a push credential on this feed, without anyone having to
 * hold a long-lived API key.
 */
final class Publishers extends Controller
{
    public function index(int $feedId): ResponseInterface
    {
        $feed = $this->requireFeed($feedId);

        return $this->response->setBody(view('admin/publishers/index', [
            'feed'       => $feed,
            'publishers' => model(TrustedPublisherModel::class)->forFeed($feedId),
            'audience'   => rtrim(base_url(), '/'),
            'providers'  => config(TrustedPublishing::class)->providerNames(),
            'errors'     => [],
        ]));
    }

    public function store(int $feedId): ResponseInterface
    {
        $feed = $this->requireFeed($feedId);

        $providers = config(TrustedPublishing::class)->providerNames();
        $provider  = trim((string) $this->request->getPost('provider'));
        // The <select> always sends one; a blank value only happens when a
        // caller posts directly (an old integration, a test) — 'github' was
        // the only provider before this field existed, so that is what
        // omitting it still means.
        $provider    = $provider === '' ? 'github' : $provider;
        $repository  = trim((string) $this->request->getPost('repository'));
        $ownerId     = trim((string) $this->request->getPost('repository_owner_id'));
        $environment = trim((string) $this->request->getPost('environment'));
        $workflow    = trim((string) $this->request->getPost('workflow'));
        $pattern     = trim((string) $this->request->getPost('id_pattern'));
        $canCreate   = (bool) $this->request->getPost('can_create_package');

        $errors = [];

        if (! in_array($provider, $providers, true)) {
            $errors[] = 'Unknown or disabled provider.';
        }

        // owner/repo (GitHub) or namespace/subgroup/.../project (GitLab,
        // which nests groups) — at least one slash, no bare name.
        if (preg_match('#\A[\w.-]+(?:/[\w.-]+)+\z#', $repository) !== 1) {
            $errors[] = 'The repository must be shaped like "account/repo" (GitLab: "group/subgroup/project" is fine too).';
        }

        if (preg_match('/\A\d+\z/', $ownerId) !== 1) {
            $errors[] = 'The numeric account/namespace id is required (a typo here means nothing will ever authenticate).';
        }

        if ($errors !== []) {
            return $this->response->setBody(view('admin/publishers/index', [
                'feed'       => $feed,
                'publishers' => model(TrustedPublisherModel::class)->forFeed($feedId),
                'audience'   => rtrim(base_url(), '/'),
                'providers'  => $providers,
                'errors'     => $errors,
            ]));
        }

        model(TrustedPublisherModel::class)->insert([
            'feed_id'             => $feedId,
            'user_id'             => auth()->id(),
            'provider'            => $provider,
            'repository'          => $repository,
            'repository_owner_id' => $ownerId,
            'environment'         => $environment === '' ? null : $environment,
            'workflow'            => $workflow === '' ? null : $workflow,
            'id_pattern'          => $pattern === '' ? null : $pattern,
            'can_create_package'  => $canCreate,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/publishers'))
            ->with('message', sprintf('"%s" now trusts %s.', $feed['name'], $repository));
    }

    public function destroy(int $feedId, int $publisherId): ResponseInterface
    {
        $this->requireFeed($feedId);

        model(TrustedPublisherModel::class)
            ->where('id', $publisherId)
            ->where('feed_id', $feedId)
            ->delete();

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/publishers'))
            ->with('message', 'Trusted publisher removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireFeed(int $id): array
    {
        $feed = model(FeedModel::class)->find($id);

        if ($feed === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $feed;
    }
}
