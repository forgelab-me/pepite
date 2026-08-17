<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authenticates a NuGet client by its API key.
 *
 * The client sends `X-NuGet-ApiKey`, not `Authorization: Bearer`, so Shield's
 * own `tokens` filter does not apply: its authenticator reads the bearer
 * header directly. Everything else is Shield's — the key is one of its access
 * tokens, hashed in auth_identities, with its scopes and its last-used
 * timestamp. No security logic is duplicated here.
 *
 * The required scope is passed as a filter argument:
 *     ['filter' => 'nugetkey:packages.push']
 */
final class NuGetApiKey implements FilterInterface
{
    public const HEADER = 'X-NuGet-ApiKey';

    // Dots, not colons: CodeIgniter's own filter syntax splits a route
    // filter string on ':' with a hard two-way explode, so
    // 'nugetkey:packages:push' silently truncates to argument 'packages'
    // and drops 'push'. Confirmed against Filters::getCleanName().
    public const SCOPE_PUSH   = 'packages.push';
    public const SCOPE_UNLIST = 'packages.unlist';

    /**
     * @param array|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $key = trim($request->getHeaderLine(self::HEADER));

        if ($key === '') {
            return $this->refuse(401, 'Missing API key. Send it in the X-NuGet-ApiKey header.');
        }

        $authenticator = auth('tokens')->getAuthenticator();

        // check() rather than attempt(): attempt() re-reads the key from the
        // Authorization header, which a NuGet client never sets.
        $result = $authenticator->check(['token' => $key]);

        if (! $result->isOK()) {
            return $this->refuse(401, 'The API key is not valid.');
        }

        $user = $result->extraInfo();
        $authenticator->login($user);

        $required = $arguments[0] ?? null;

        if ($required !== null && $user->currentAccessToken()?->can($required) !== true) {
            return $this->refuse(403, sprintf('This API key lacks the "%s" scope.', $required));
        }
    }

    /**
     * @param array|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }

    private function refuse(int $status, string $message): ResponseInterface
    {
        return service('response')
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    }
}
