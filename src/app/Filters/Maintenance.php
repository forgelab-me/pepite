<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Refuses NuGet protocol traffic while a release is being applied.
 *
 * The server can be serving restores at any moment, and self-update replaces
 * files out from under a running request. Toggled by
 * `php spark pepite:maintenance on|off` around an update, not wired
 * automatically into ci4-updater's apply step — it exposes no hook to attach
 * to, so this is a manual bracket rather than an automatic one.
 */
final class Maintenance implements FilterInterface
{
    public const FLAG_FILE = WRITEPATH . 'maintenance.flag';

    /**
     * @param array|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! is_file(self::FLAG_FILE)) {
            return;
        }

        return service('response')
            ->setStatusCode(503)
            ->setHeader('Retry-After', '60')
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => 'A release is being applied. Try again shortly.'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
