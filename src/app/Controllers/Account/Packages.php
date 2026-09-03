<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\FeedModel;
use App\Models\PackageModel;
use App\Models\PackageOwnerModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * "My packages" — every package the logged-in user owns, across every feed.
 * The self-service counterpart to Admin\Packages, scoped by ownership
 * instead of the admin group: show()/unlist()/relist() mirror
 * Admin\Packages' own versions almost exactly, just with
 * requireOwnedPackage() standing in for requireFeed()+requirePackage().
 * Deleting stays admin-console-only (superadmin, in fact) — not extended
 * here.
 */
final class Packages extends Controller
{
    public function index(): ResponseInterface
    {
        $packages = model(PackageModel::class)->ownedBy((int) auth()->id());

        return $this->response->setBody(view('account/packages/index', ['packages' => $packages]));
    }

    public function show(int $packageId): ResponseInterface
    {
        $package = $this->requireOwnedPackage($packageId);
        $feed    = model(FeedModel::class)->find($package['feed_id']);

        return $this->response->setBody(view('account/packages/show', [
            'feed'     => $feed,
            'package'  => $package,
            'versions' => array_reverse(model(PackageVersionModel::class)->forPackage($packageId)),
        ]));
    }

    public function unlist(int $packageId, int $versionId): ResponseInterface
    {
        return $this->setListed($packageId, $versionId, false);
    }

    public function relist(int $packageId, int $versionId): ResponseInterface
    {
        return $this->setListed($packageId, $versionId, true);
    }

    private function setListed(int $packageId, int $versionId, bool $listed): ResponseInterface
    {
        $this->requireOwnedPackage($packageId);

        $versions = model(PackageVersionModel::class);
        $version  = $versions->where('id', $versionId)->where('package_id', $packageId)->first();

        if ($version === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Delisting never deletes — same rule Admin\Packages applies:
        // published versions are immutable, only their visibility in
        // search/registration changes.
        $versions->update($versionId, ['is_listed' => $listed]);

        return redirect()
            ->to(site_url('account/packages/' . $packageId))
            ->with('message', $listed ? 'Version relisted.' : 'Version delisted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedPackage(int $packageId): array
    {
        $package = model(PackageModel::class)->find($packageId);

        if ($package === null || ! model(PackageOwnerModel::class)->owns($packageId, (int) auth()->id())) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $package;
    }
}
