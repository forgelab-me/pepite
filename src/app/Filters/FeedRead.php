<?php

declare(strict_types=1);

namespace App\Filters;

use App\Models\FeedApiKeyRuleModel;
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
 * The scope check alone is not enough: it says nothing about *which* feed
 * the key was meant for, so a key restricted to one feed's
 * feed_api_key_rules row is additionally confined to reading only the
 * feed(s) that row names, the same "no row = unrestricted" rule
 * PublishAuthorizer already applies to pushes.
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

        // Mirrors PublishAuthorizer::authorizeKeyReach's own rule: a key with
        // no row anywhere in feed_api_key_rules is unrestricted, matching a
        // plain nuget.org key. One with a row is confined to the feed(s) it
        // names — without this, packages.read alone let a key scoped to one
        // feed read every *other* private feed on the instance too, since
        // that scope carries no notion of which feed it was meant for.
        $rules = model(FeedApiKeyRuleModel::class);

        if ($rules->hasAnyRule((int) $token->id) && $rules->forIdentityAndFeed((int) $token->id, (int) $feed['id']) === []) {
            return service('response')
                ->setStatusCode(403)
                ->setContentType('application/json')
                ->setBody(json_encode(['error' => 'This API key is not allowed to read this feed.'], JSON_UNESCAPED_SLASHES));
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
