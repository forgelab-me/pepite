<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The one place that says what protects what.
 *
 * Authorization in this app is spread over route filters, in-controller
 * checks and PublishAuthorizer, and nothing forces a new route to pick any of
 * them: an `admin/*` route is only safe because it happens to be declared
 * inside the group that carries group:admin. This walks every registered
 * route and fails if one sits in no known area, or in a known area without
 * that area's filters — so adding a route either lands in an existing
 * policy or makes someone write a new one here, on purpose.
 *
 * @internal
 */
final class RouteInventoryTest extends CIUnitTestCase
{
    private const VERBS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', '*'];

    /**
     * Reachable by anyone, deliberately. A prefix ends in '/'; anything else
     * is an exact route.
     */
    private const PUBLIC_ROUTES = [
        '/',
        'search',
        'browse/',
        // Shield's own screens (login, register, magic link, logout, 2FA/activation actions).
        'login',
        'login/',
        'logout',
        'register',
        'auth/a/',
    ];

    public function testEveryRouteIsCoveredByAPolicy(): void
    {
        $failures = [];

        foreach ($this->routes() as [$verb, $route, $filters]) {
            $problem = $this->violation($verb, $route, $filters);

            if ($problem !== null) {
                $failures[] = sprintf('%-6s %s — %s [filters: %s]', $verb, $route, $problem, implode(', ', $filters) ?: 'none');
            }
        }

        $this->assertSame([], $failures, "Routes outside every policy:\n" . implode("\n", $failures));
    }

    /**
     * Guards the test itself: if route discovery silently returned nothing,
     * the test above would pass for the wrong reason.
     */
    public function testTheInventoryActuallyFindsTheRoutes(): void
    {
        $seen = array_map(static fn (array $r): string => $r[0] . ' ' . $r[1], $this->routes());

        $this->assertContains('POST admin/feeds/([0-9]+)/packages/([0-9]+)/purge', $seen);
        $this->assertContains('PUT feeds/([^/]+)/api/v2/package', $seen);
        $this->assertContains('GET account/keys', $seen);
        $this->assertGreaterThan(40, count($seen));
    }

    /**
     * @param list<string> $filters
     */
    private function violation(string $verb, string $route, array $filters): ?string
    {
        $names   = array_map(static fn (string $f): string => explode(':', $f, 2)[0], $filters);
        $changes = $verb !== 'GET';

        // The self-update panel routes come from forgelab-me/ci4-updater,
        // mounted under admin/ with the same group filter.
        if ($this->startsWith($route, 'admin/')) {
            if (! in_array('group:admin', $filters, true)) {
                return 'admin/* must carry group:admin';
            }

            if ($changes && ! in_array('csrf', $names, true)) {
                return 'a state-changing admin route must carry csrf';
            }

            return null;
        }

        if ($route === 'account' || $this->startsWith($route, 'account/')) {
            if (! in_array('session', $names, true)) {
                return 'account/* must carry session';
            }

            return $changes && ! in_array('csrf', $names, true) ? 'a state-changing account route must carry csrf' : null;
        }

        // Route keys are the compiled patterns: (:segment) is ([^/]+).
        if ($this->startsWith($route, 'feeds/([^/]+)/v3/')) {
            return in_array('feedread', $names, true) ? null : 'the read protocol must carry feedread';
        }

        if ($this->startsWith($route, 'feeds/([^/]+)/api/v2/')) {
            // Trusted Publishing: the credential presented is an OIDC token,
            // verified by hand in PublishToken, not a NuGet API key.
            if ($route === 'feeds/([^/]+)/api/v2/publish/token') {
                return in_array('ratelimit', $names, true) ? null : 'minting a key must be rate limited';
            }

            return in_array('nugetkey', $names, true) ? null : 'the publish protocol must carry nugetkey';
        }

        if ($this->startsWith($route, 'install')) {
            // Self-locks via writable/install.lock; its forms are CSRF-protected.
            return in_array('csrf', $names, true) ? null : 'the installer must carry csrf';
        }

        foreach (self::PUBLIC_ROUTES as $public) {
            if ($route === $public || (str_ends_with($public, '/') && $this->startsWith($route, $public))) {
                return null;
            }
        }

        return 'matches no policy — add it to a known area or declare it public here, on purpose';
    }

    /**
     * @return list<array{string, string, list<string>}>
     */
    private function routes(): array
    {
        $collection = service('routes');
        $collection->loadRoutes();

        $found = [];

        foreach (self::VERBS as $verb) {
            foreach (array_keys($collection->getRoutes($verb, false)) as $route) {
                $found[] = [$verb, (string) $route, $collection->getFiltersForRoute((string) $route, $verb)];
            }
        }

        return $found;
    }

    private function startsWith(string $route, string $prefix): bool
    {
        return str_starts_with($route, $prefix);
    }
}
