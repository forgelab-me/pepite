<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Models\PackageModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * "My packages" — every package the logged-in user owns, across every feed.
 * The self-service counterpart to Admin\Packages, scoped to one account
 * instead of one admin's view of everything.
 */
final class Packages extends Controller
{
    public function index(): ResponseInterface
    {
        $packages = model(PackageModel::class)->ownedBy((int) auth()->id());

        return $this->response->setBody(view('account/packages/index', ['packages' => $packages]));
    }
}
