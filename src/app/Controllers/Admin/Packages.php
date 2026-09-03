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
 *
 * unlist()/relist() need only 'admin', same as everything else here.
 * purge()/purgeVersion() need 'superadmin' too — see requireSuperadmin()
 * for why that's checked here rather than as a second route filter.
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

    /**
     * Permanently deletes an entire package — every version, its files, its
     * ownership records. The console equivalent of `pepite:purge`, gated
     * one level narrower than the rest of this controller: every action
     * above only needs 'admin', this needs 'superadmin' too, since unlike
     * delisting there is no way back from this one.
     */
    public function purge(int $feedId, int $packageId): ResponseInterface
    {
        if (($denied = $this->requireSuperadmin($feedId, $packageId)) !== null) {
            return $denied;
        }

        $package = $this->requirePackage($feedId, $packageId);

        if (! $this->confirmed($package)) {
            return redirect()
                ->to(site_url('admin/feeds/' . $feedId . '/packages/' . $packageId))
                ->with('error', 'Type the package identifier exactly to confirm.');
        }

        $versions = model(PackageVersionModel::class)->forPackage($packageId);

        service('packagePurger')->purge($package, $versions, deletePackageToo: true);

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/packages'))
            ->with('message', sprintf('"%s" permanently deleted.', $package['package_id']));
    }

    /**
     * Permanently deletes one version. If it is the package's last one, the
     * package identifier goes with it — same rule `pepite:purge` applies.
     */
    public function purgeVersion(int $feedId, int $packageId, int $versionId): ResponseInterface
    {
        if (($denied = $this->requireSuperadmin($feedId, $packageId)) !== null) {
            return $denied;
        }

        $package = $this->requirePackage($feedId, $packageId);

        if (! $this->confirmed($package)) {
            return redirect()
                ->to(site_url('admin/feeds/' . $feedId . '/packages/' . $packageId))
                ->with('error', 'Type the package identifier exactly to confirm.');
        }

        $allVersions = model(PackageVersionModel::class)->forPackage($packageId);
        $target      = null;

        foreach ($allVersions as $row) {
            if ((int) $row['id'] === $versionId) {
                $target = $row;

                break;
            }
        }

        if ($target === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $deletePackageToo = count($allVersions) === 1;

        service('packagePurger')->purge($package, [$target], $deletePackageToo);

        if ($deletePackageToo) {
            return redirect()
                ->to(site_url('admin/feeds/' . $feedId . '/packages'))
                ->with('message', sprintf('"%s" permanently deleted.', $package['package_id']));
        }

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/packages/' . $packageId))
            ->with('message', sprintf('Version %s permanently deleted.', $target['version_normalized']));
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
            ->with('message', $listed ? 'Version relisted.' : 'Version delisted.');
    }

    /**
     * The `admin/*` route group already requires 'admin'. Stacking a
     * second `group:superadmin` route filter would not express "admin OR
     * superadmin" — CI4 merges group and route filters rather than
     * replacing them, so both `group:admin` and `group:superadmin` would
     * run as independent checks, each demanding its own membership: a
     * superadmin who isn't also in 'admin' would be refused by the first
     * filter before ever reaching this check. Checking here instead keeps
     * the requirement to exactly "superadmin", on top of whatever already
     * got the request this far.
     */
    private function requireSuperadmin(int $feedId, int $packageId): ?ResponseInterface
    {
        if (auth()->user()->inGroup('superadmin')) {
            return null;
        }

        return redirect()
            ->to(site_url('admin/feeds/' . $feedId . '/packages/' . $packageId))
            ->with('error', 'Superadmin required to delete a package or version.');
    }

    /**
     * @param array<string, mixed> $package
     */
    private function confirmed(array $package): bool
    {
        return $this->request->getPost('confirm') === $package['package_id'];
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
