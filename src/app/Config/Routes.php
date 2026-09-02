<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Web installer. No group:admin (no admin exists yet); the controller
// self-locks after first use via writable/install.lock.
$routes->get('install', 'Install::index', ['filter' => 'csrf']);
$routes->post('install', 'Install::store', ['filter' => 'csrf']);

// Public browsing, public feeds only.
$routes->get('/', 'Web\Feeds::index');
$routes->get('search', 'Web\Feeds::search');
$routes->get('browse/(:segment)', 'Web\Feeds::show/$1');
$routes->get('browse/(:segment)/recent.atom', 'Web\Feeds::recent/$1');
$routes->get('browse/(:segment)/(:segment)', 'Web\Packages::show/$1/$2');
$routes->get('browse/(:segment)/(:segment)/(:segment)', 'Web\Packages::show/$1/$2/$3');

// Shield: login, logout, register, magic link, password reset.
service('auth')->routes($routes);

// Self-update panel. `group:admin` already covers "must be logged in".
service('updater')->routes($routes, ['prefix' => 'admin', 'filter' => 'group:admin']);

// Admin console: feeds and API keys. CSRF is explicit here — the NuGet
// protocol groups below must never carry it, so it cannot be a global.
$routes->group('admin', ['namespace' => 'App\Controllers\Admin', 'filter' => ['group:admin', 'csrf']], static function ($routes): void {
    $routes->get('feeds', 'Feeds::index');
    $routes->get('feeds/create', 'Feeds::create');
    $routes->post('feeds', 'Feeds::store');
    $routes->get('feeds/(:num)/edit', 'Feeds::edit/$1');
    $routes->post('feeds/(:num)', 'Feeds::update/$1');
    $routes->post('feeds/(:num)/delete', 'Feeds::destroy/$1');

    $routes->get('feeds/(:num)/packages', 'Packages::index/$1');
    $routes->get('feeds/(:num)/packages/(:num)', 'Packages::show/$1/$2');
    $routes->post('feeds/(:num)/packages/(:num)/versions/(:num)/unlist', 'Packages::unlist/$1/$2/$3');
    $routes->post('feeds/(:num)/packages/(:num)/versions/(:num)/relist', 'Packages::relist/$1/$2/$3');

    $routes->get('feeds/(:num)/publishers', 'Publishers::index/$1');
    $routes->post('feeds/(:num)/publishers', 'Publishers::store/$1');
    $routes->post('feeds/(:num)/publishers/(:num)/delete', 'Publishers::destroy/$1/$2');

    $routes->get('users', 'Users::index');
    $routes->get('users/create', 'Users::create');
    $routes->post('users', 'Users::store');
    $routes->post('users/(:num)/delete', 'Users::destroy/$1');

    $routes->get('keys', 'ApiKeys::index');
    $routes->get('keys/create', 'ApiKeys::create');
    $routes->post('keys', 'ApiKeys::store');
    $routes->get('keys/(:num)/edit', 'ApiKeys::edit/$1');
    $routes->post('keys/(:num)', 'ApiKeys::update/$1');
    $routes->post('keys/(:num)/revoke', 'ApiKeys::revoke/$1');
});

// Self-service: any logged-in user manages their own packages and keys, no
// group required — unlike admin/, which is admins only.
$routes->group('account', ['namespace' => 'App\Controllers\Account', 'filter' => ['session', 'csrf']], static function ($routes): void {
    $routes->get('/', 'Packages::index');
    $routes->get('keys', 'ApiKeys::index');
    $routes->get('keys/create', 'ApiKeys::create');
    $routes->post('keys', 'ApiKeys::store');
    $routes->post('keys/(:num)/revoke', 'ApiKeys::revoke/$1');
});

/*
 * The NuGet protocol. These routes must never inherit the session or CSRF
 * filters: the caller is a command line tool with neither cookies nor tokens.
 *
 * Registration is exposed under two base paths that differ only in whether
 * SemVer 2 versions are visible — that is how the protocol filters them, and
 * the client picks one from the service index.
 */
$routes->group('feeds/(:segment)/v3', [
    'namespace' => 'App\Controllers\V3',
    // Public feeds pass through untouched; a private one demands Basic auth
    // with an API key as the password. See App\Filters\FeedRead. Nothing
    // here lists 'maintenance': it's a global filter now (Config\Filters).
    'filter' => ['feedread'],
], static function ($routes): void {
    $routes->get('index.json', 'ServiceIndex::show/$1');

    $routes->get('flatcontainer/(:segment)/index.json', 'FlatContainer::versions/$1/$2');
    $routes->get('flatcontainer/(:segment)/(:segment)/(:segment)', 'FlatContainer::file/$1/$2/$3/$4');

    $routes->get('registration/(:segment)/index.json', 'Registration::index/$1/$2');
    $routes->get('registration/(:segment)/(:segment)', 'Registration::leaf/$1/$2/$3');

    $routes->get('registration-semver2/(:segment)/index.json', 'Registration::indexSemVer2/$1/$2');
    $routes->get('registration-semver2/(:segment)/(:segment)', 'Registration::leafSemVer2/$1/$2/$3');

    $routes->get('search', 'Search::query/$1');
    $routes->get('autocomplete', 'Search::autocomplete/$1');
});

/*
 * PackagePublish/2.0.0. The API key carries its own scope, so the filter is
 * declared per route rather than per group: pushing and delisting are not the
 * same permission.
 */
$routes->group('feeds/(:segment)/api/v2', ['namespace' => 'App\Controllers\Api'], static function ($routes): void {
    // ratelimit runs first on every route below: it's IP-scoped and rejects
    // for free, so an abusive caller is turned away before the more
    // expensive checks after it (API key lookup, JWT verification) ever run.
    // 'maintenance' isn't listed: it's a global filter now (Config\Filters).
    $routes->put('package', 'PackagePublish::push/$1', ['filter' => ['ratelimit:push', 'nugetkey:packages.push']]);
    $routes->put('package/', 'PackagePublish::push/$1', ['filter' => ['ratelimit:push', 'nugetkey:packages.push']]);

    $routes->put('symbolpackage', 'PackagePublish::pushSymbols/$1', ['filter' => ['ratelimit:push', 'nugetkey:packages.push']]);
    $routes->put('symbolpackage/', 'PackagePublish::pushSymbols/$1', ['filter' => ['ratelimit:push', 'nugetkey:packages.push']]);

    $routes->delete('package/(:segment)/(:segment)', 'PackagePublish::unlist/$1/$2/$3', ['filter' => ['ratelimit:push', 'nugetkey:packages.unlist']]);
    $routes->post('package/(:segment)/(:segment)', 'PackagePublish::relist/$1/$2/$3', ['filter' => ['ratelimit:push', 'nugetkey:packages.unlist']]);

    // Trusted Publishing: exchanges a GitHub Actions or GitLab CI/CD OIDC
    // token for a scoped NuGet API key. No nugetkey filter — the credential
    // presented here is the OIDC token, not a NuGet API key, and
    // PublishToken verifies it by hand against each enabled provider's own
    // signing keys.
    $routes->post('publish/token', 'PublishToken::mint/$1', ['filter' => ['ratelimit:token']]);
});
