<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FeedModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Trusted Publishing admin: which GitHub Actions workflows may exchange
 * their OIDC identity for a push credential on this feed, without anyone
 * having to hold a long-lived API key.
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
            'errors'     => [],
        ]));
    }

    public function store(int $feedId): ResponseInterface
    {
        $feed = $this->requireFeed($feedId);

        $repository  = trim((string) $this->request->getPost('repository'));
        $ownerId     = trim((string) $this->request->getPost('repository_owner_id'));
        $environment = trim((string) $this->request->getPost('environment'));
        $pattern     = trim((string) $this->request->getPost('id_pattern'));
        $canCreate   = (bool) $this->request->getPost('can_create_package');

        $errors = [];

        if (preg_match('#\A[\w.-]+/[\w.-]+\z#', $repository) !== 1) {
            $errors[] = 'Le dépôt doit avoir la forme "compte/repo".';
        }

        if (preg_match('/\A\d+\z/', $ownerId) !== 1) {
            $errors[] = "L'identifiant numérique du compte GitHub est requis (une faute de frappe ici et rien ne s'authentifiera jamais).";
        }

        if ($errors !== []) {
            return $this->response->setBody(view('admin/publishers/index', [
                'feed'       => $feed,
                'publishers' => model(TrustedPublisherModel::class)->forFeed($feedId),
                'audience'   => rtrim(base_url(), '/'),
                'errors'     => $errors,
            ]));
        }

        model(TrustedPublisherModel::class)->insert([
            'feed_id'             => $feedId,
            'user_id'             => auth()->id(),
            'provider'            => 'github',
            'repository'          => $repository,
            'repository_owner_id' => $ownerId,
            'environment'         => $environment === '' ? null : $environment,
            'id_pattern'          => $pattern === '' ? null : $pattern,
            'can_create_package'  => $canCreate,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/publishers'))
            ->with('message', sprintf('"%s" fait maintenant confiance à %s.', $feed['name'], $repository));
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
            ->with('message', 'Publieur de confiance retiré.');
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
