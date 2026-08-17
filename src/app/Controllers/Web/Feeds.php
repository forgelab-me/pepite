<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\FeedModel;
use App\Models\PackageModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public browsing. Only public feeds — a private one has no admin console
 * (lot 6 is public nav + admin), so anonymous browsing of it stays a 404
 * rather than leaking that it exists.
 */
final class Feeds extends Controller
{
    public function index(): ResponseInterface
    {
        $feeds = model(FeedModel::class)->where('visibility', 'public')->orderBy('name', 'ASC')->findAll();

        return $this->response->setBody(view('web/feeds/index', ['feeds' => $feeds]));
    }

    public function show(string $slug): ResponseInterface
    {
        $feed = model(FeedModel::class)->findBySlug($slug);

        if ($feed === null || $feed['visibility'] !== 'public') {
            return $this->notFound();
        }

        $query  = (string) ($this->request->getGet('q') ?? '');
        $result = model(PackageModel::class)->search((int) $feed['id'], $query, 0, 50, includePrerelease: true, semVer2: true);

        return $this->response->setBody(view('web/feeds/show', [
            'feed'     => $feed,
            'packages' => $result['packages'],
            'query'    => $query,
        ]));
    }

    private function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404', ['message' => 'Page introuvable']));
    }
}
