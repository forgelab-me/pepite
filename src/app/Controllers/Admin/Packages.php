<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Browsing and moderating packages from the admin console: unlike the public
 * pages (Web\Feeds, Web\Packages), this sees private feeds and unlisted
 * versions, and can act on them.
 */
final class Packages extends Controller
{
    public function index(int $feedId): ResponseInterface
    {
        $feed     = $this->requireFeed($feedId);
        $packages = model(PackageModel::class)->forFeed($feedId);

        return $this->response->setBody(view('admin/packages/index', [
            'feed'     => $feed,
            'packages' => $packages,
        ]));
    }

    public function show(int $feedId, int $packageId): ResponseInterface
    {
        $feed    = $this->requireFeed($feedId);
        $package = $this->requirePackage($feedId, $packageId);

        $versions = model(PackageVersionModel::class)->forPackage($packageId);
        $owners   = $this->ownerNames($packageId);

        return $this->response->setBody(view('admin/packages/show', [
            'feed'     => $feed,
            'package'  => $package,
            'versions' => array_reverse($versions),
            'owners'   => $owners,
        ]));
    }

    public function unlist(int $feedId, int $packageId, int $versionId): ResponseInterface
    {
        return $this->setListed($feedId, $packageId, $versionId, false);
    }

    public function relist(int $feedId, int $packageId, int $versionId): ResponseInterface
    {
        return $this->setListed($feedId, $packageId, $versionId, true);
    }

    private function setListed(int $feedId, int $packageId, int $versionId, bool $listed): ResponseInterface
    {
        $this->requireFeed($feedId);
        $this->requirePackage($feedId, $packageId);

        $versions = model(PackageVersionModel::class);
        $version  = $versions->where('id', $versionId)->where('package_id', $packageId)->first();

        if ($version === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Delisting never deletes: published versions are immutable (PLAN.md
        // 9.3), and anything already depending on this one must keep
        // restoring. Only its visibility in search/registration changes.
        $versions->update($versionId, ['is_listed' => $listed]);

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/packages/' . $packageId))
            ->with('message', $listed ? 'Version relistée.' : 'Version délistée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireFeed(int $feedId): array
    {
        $feed = model(FeedModel::class)->find($feedId);

        if ($feed === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $feed;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePackage(int $feedId, int $packageId): array
    {
        $package = model(PackageModel::class)->where('id', $packageId)->where('feed_id', $feedId)->first();

        if ($package === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $package;
    }

    /**
     * @return list<string>
     */
    private function ownerNames(int $packageId): array
    {
        $userIds = model(PackageOwnerModel::class)->userIdsFor($packageId);

        if ($userIds === []) {
            return [];
        }

        return array_column(
            db_connect()->table('users')->select('username')->whereIn('id', $userIds)->get()->getResultArray(),
            'username',
        );
    }
}
