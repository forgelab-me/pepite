<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Gates reads on a private feed.
 *
 * A public feed is untouched by this filter: no Authorization header is even
 * looked at, matching what the plan promises — `dotnet restore` against a
 * public feed needs zero configuration anywhere.
 *
 * A private feed requires HTTP Basic auth, which is the only mechanism every
 * NuGet client actually implements. The password is an API key with the
 * packages.read scope, not the account's real password: nuget.config stores
 * it in clear text on Linux and macOS, so it must be something narrow and
 * revocable. The username is not checked at all — it can be anything.
 *
 * An unknown feed is left alone: the controller answers its own 404, and this
 * filter has no business deciding what "unknown" means.
 */
final class FeedRead implements FilterInterface
{
    public const REALM = 'Pepite';

    /**
     * @param array|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $slug = $request->getUri()->getSegment(2);
        $feed = service('feedResolver')->find($slug);

        if ($feed === null || ($feed['visibility'] ?? 'public') !== 'private') {
            return;
        }

        $credentials = $this->parseBasicAuth($request->getHeaderLine('Authorization'));

        if ($credentials === null) {
            return $this->challenge('This feed is private. Send an API key as the Basic auth password.');
        }

        [, $key] = $credentials;

        $authenticator = auth('tokens')->getAuthenticator();
        $result        = $authenticator->check(['token' => $key]);

        if (! $result->isOK()) {
            return $this->challenge('The API key is not valid.');
        }

        $token = $result->extraInfo()->currentAccessToken();

        if ($token?->can('packages.read') !== true) {
            return service('response')
                ->setStatusCode(403)
                ->setContentType('application/json')
                ->setBody(json_encode(['error' => 'This API key lacks the "packages.read" scope.'], JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * @param array|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }

    /**
     * @return array{0: string, 1: string}|null [username, password]
     */
    private function parseBasicAuth(string $header): ?array
    {
        if (! str_starts_with(strtolower(trim($header)), strtolower('Basic '))) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return $password === '' ? null : [$username, $password];
    }

    private function challenge(string $message): ResponseInterface
    {
        return service('response')
            ->setStatusCode(401)
            ->setHeader('WWW-Authenticate', sprintf('Basic realm="%s"', self::REALM))
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    }
}
