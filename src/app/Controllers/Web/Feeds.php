<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public browsing. Only public feeds — a private one has no admin console
 * (lot 6 is public nav + admin), so anonymous browsing of it stays a 404
 * rather than leaking that it exists.
 */
final class Feeds extends Controller
{
    private const VALID_SORTS = ['downloads', 'name'];

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

        $query = (string) ($this->request->getGet('q') ?? '');
        $sort  = $this->sortParam();

        $result = model(PackageModel::class)->search(
            (int) $feed['id'],
            $query,
            0,
            50,
            includePrerelease: true,
            semVer2: true,
            sort: $sort,
        );

        $packages = array_map($this->withDisplayFields($feed['slug']), $result['packages']);

        return $this->response->setBody(view('web/feeds/show', [
            'feed'     => $feed,
            'packages' => $packages,
            'query'    => $query,
            'sort'     => $sort,
        ]));
    }

    /**
     * The Atom counterpart to browsing a feed by eye: the last 30 versions
     * published here, across every package, for whoever would rather watch
     * a feed reader than come back and check.
     */
    public function recent(string $slug): ResponseInterface
    {
        $feed = model(FeedModel::class)->findBySlug($slug);

        if ($feed === null || $feed['visibility'] !== 'public') {
            return $this->notFound();
        }

        $versions = model(PackageVersionModel::class)->recentInFeed((int) $feed['id']);

        return $this->response
            ->setContentType('application/atom+xml')
            ->setBody(view('web/feeds/recent_atom', [
                'feed'      => $feed,
                'versions'  => $versions,
                'selfUrl'   => current_url(),
                'feedUrl'   => site_url('browse/' . $feed['slug']),
                'generated' => $versions === [] ? null : (string) $versions[0]['created_at'],
                // The debug toolbar wraps every view() in an HTML comment
                // in dev/testing (View::render(), gated on ENVIRONMENT !==
                // 'production') — harmless for an HTML page, fatal here:
                // the XML declaration has to be the very first byte.
            ], ['debug' => false]));
    }

    /**
     * The home page's search box: every public feed at once, since that is
     * the one place nobody has already picked a feed to be inside of.
     */
    public function search(): ResponseInterface
    {
        $query = (string) ($this->request->getGet('q') ?? '');
        $sort  = $this->sortParam();

        $result = model(PackageModel::class)->search(
            null,
            $query,
            0,
            50,
            includePrerelease: true,
            semVer2: true,
            sort: $sort,
        );

        $packages = array_map(
            fn (array $package) => ($this->withDisplayFields((string) $package['feed_slug']))($package),
            $result['packages'],
        );

        return $this->response->setBody(view('web/search', [
            'packages' => $packages,
            'query'    => $query,
            'sort'     => $sort,
        ]));
    }

    private function sortParam(): string
    {
        $sort = (string) ($this->request->getGet('sort') ?? 'downloads');

        return in_array($sort, self::VALID_SORTS, true) ? $sort : 'downloads';
    }

    /**
     * @return callable(array<string, mixed>): array<string, mixed>
     */
    private function withDisplayFields(string $slug): callable
    {
        return static function (array $package) use ($slug): array {
            $package['_feedSlug'] = $slug;

            $tags = json_decode((string) ($package['latest_tags'] ?? ''), true);

            $package['tags'] = is_array($tags) ? array_values(array_map('strval', $tags)) : [];

            $package['iconUrl'] = null;

            if (! empty($package['latest_icon_path'])) {
                $package['iconUrl'] = site_url(sprintf(
                    'feeds/%s/v3/flatcontainer/%s/%s/icon',
                    $slug,
                    $package['package_id_lower'],
                    $package['latest_version_normalized_lower'],
                ));
            } elseif (! empty($package['latest_icon_url'])) {
                $package['iconUrl'] = $package['latest_icon_url'];
            }

            return $package;
        };
    }

    private function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404', ['message' => 'Page introuvable']));
    }
}
