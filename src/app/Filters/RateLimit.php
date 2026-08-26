<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * IP-scoped rate limiting for endpoints nothing else throttles.
 *
 * The NuGet push endpoint and the Trusted Publishing token exchange both
 * accept a credential that is only verified *inside* the request — an API
 * key lookup, a JWT signature check possibly followed by a JWKS fetch — so
 * nothing before this filter stops a script from hammering either one
 * trying keys or malformed tokens. Shield's own AuthRates filter (wired in
 * Config\Filters) covers the same gap for login/register.
 *
 * Presets rather than raw numbers in the route: CodeIgniter's filter
 * argument parsing splits on the first ':' only (see App\Filters\NuGetApiKey
 * for the same constraint), so a single name is what a filter string can
 * carry cleanly.
 */
final class RateLimit implements FilterInterface
{
    /**
     * preset => [capacity, window in seconds].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const PRESETS = [
        // A CI matrix can legitimately push several packages back to back;
        // sized to survive that burst, not a single publish.
        'push' => [60, MINUTE],
        // One mint per publish, so the same burst is all this needs to
        // absorb — kept tighter since a miss here means verifying a JWT,
        // and possibly fetching GitHub's JWKS, for nothing.
        'token' => [30, MINUTE],
    ];

    private const DEFAULT_PRESET = [30, MINUTE];

    /**
     * @param array|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $preset               = (string) ($arguments[0] ?? '');
        [$capacity, $seconds] = self::PRESETS[$preset] ?? self::DEFAULT_PRESET;

        $throttler = service('throttler');
        $key       = 'ratelimit_' . $preset . '_' . md5($request->getIPAddress());

        if ($throttler->check($key, $capacity, $seconds, 1) === false) {
            return service('response')
                ->setStatusCode(429)
                ->setContentType('application/json')
                ->setBody(json_encode(
                    ['error' => sprintf('Too many requests. Try again in %d seconds.', $throttler->getTokenTime())],
                    JSON_UNESCAPED_SLASHES,
                ));
        }
    }

    /**
     * @param array|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
