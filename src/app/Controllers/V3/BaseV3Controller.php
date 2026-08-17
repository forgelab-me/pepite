<?php

declare(strict_types=1);

namespace App\Controllers\V3;

use App\Libraries\V3\FeedUrls;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared plumbing for the read side of the V3 protocol.
 *
 * These controllers are reached through a route group that carries neither the
 * session filter nor CSRF: the caller is a command line tool with no cookie
 * and no token.
 */
abstract class BaseV3Controller extends Controller
{
    /**
     * The feed named in the URL, or null.
     *
     * Deliberately not an exception: every endpoint here answers a machine, so
     * an unknown feed has to come back as a JSON 404 like every other miss —
     * not as the framework's HTML error page.
     *
     * @return array<string, mixed>|null
     */
    protected function feed(string $slug): ?array
    {
        return service('feedResolver')->find($slug);
    }

    protected function unknownFeed(string $slug): ResponseInterface
    {
        return $this->notFound(sprintf('There is no feed named "%s".', $slug));
    }

    protected function urls(string $slug): FeedUrls
    {
        return FeedUrls::fromRequest($this->request, $slug);
    }

    /**
     * Whether the caller announced it understands SemVer 2.
     *
     * Only meaningful for search and autocomplete; registration signals the
     * same thing through two separate base URLs instead.
     */
    protected function wantsSemVer2(): bool
    {
        return (string) ($this->request->getGet('semVerLevel') ?? '') !== ''
            && version_compare((string) $this->request->getGet('semVerLevel'), '2.0.0', '>=');
    }

    protected function boolQuery(string $name, bool $default): bool
    {
        $raw = $this->request->getGet($name);

        if ($raw === null || $raw === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function intQuery(string $name, int $default, int $min, int $max): int
    {
        $raw = $this->request->getGet($name);

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload): ResponseInterface
    {
        return $this->response
            ->setContentType('application/json')
            ->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function notFound(string $message): ResponseInterface
    {
        return $this->response
            ->setStatusCode(404)
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    }
}
