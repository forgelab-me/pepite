<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Filters\NuGetApiKey;
use App\Models\FeedApiKeyRuleModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserModel;
use Config\TrustedPublishing;
use Forgelabme\TrustedPublishing\PublisherMatcher;
use Forgelabme\TrustedPublishing\ScopedTokens;
use Forgelabme\TrustedPublishing\Verifier;

/**
 * Trusted Publishing: exchanges a GitHub Actions OIDC identity token for a
 * short-lived NuGet API key, so a workflow never has to hold a long-lived
 * secret in the repo's settings.
 *
 * Verification, identity normalisation and policy matching are
 * forgelab-me/ci4-trusted-publishing's job (Verifier, PublisherMatcher) —
 * this controller only owns what is Pépite-specific: which feed, which
 * scope, and how the resulting credential is authorized to push
 * (feed_api_key_rules).
 *
 * Deliberately decoupled from the push endpoint itself: this mints exactly
 * the credential an admin could already create by hand in the console — a
 * Shield access token plus a feed_api_key_rules row — so PackagePublish
 * needs no awareness that Trusted Publishing exists at all.
 *
 * No route filter: unlike the push endpoint, the credential presented here
 * is a GitHub OIDC token, not a NuGet API key, so NuGetApiKey does not apply.
 */
final class PublishToken extends Controller
{
    private const MINTED_TTL_MINUTES = 15;

    public function mint(string $slug): ResponseInterface
    {
        $feed = service('feedResolver')->find($slug);

        if ($feed === null) {
            return $this->error(404, sprintf('There is no feed named "%s".', $slug));
        }

        $verifier = config(TrustedPublishing::class)->verifier('github');

        if ($verifier === null) {
            return $this->error(500, 'GitHub Actions is not an enabled Trusted Publishing provider.');
        }

        $presented = Verifier::bearer($this->request->getHeaderLine('Authorization'));
        $result    = $verifier->verify($presented);

        if (! $result->ok) {
            return $this->error(401, $result->error);
        }

        $identity = $result->identity;
        $feedId   = (int) $feed['id'];

        $publisherModel = model(TrustedPublisherModel::class);
        $publisher      = (new PublisherMatcher())->match($publisherModel->forFeed($feedId), $identity);

        if ($publisher === null) {
            // Verbose on purpose: the alternative is an admin re-reading the
            // trusted publisher form for the tenth time looking for a typo
            // that a single log line would have named directly.
            return $this->error(403, sprintf(
                'No trusted publisher on feed "%s" matches this token (repository "%s", owner id "%s", environment "%s").',
                $slug,
                $identity->repository,
                $identity->ownerId,
                $identity->environment !== '' ? $identity->environment : '(none)',
            ));
        }

        $user = model(UserModel::class)->findById((int) $publisher['user_id']);

        if ($user === null) {
            return $this->error(403, 'The account that trusted this publisher no longer exists.');
        }

        $scopes = ['packages.read', NuGetApiKey::SCOPE_PUSH];

        // Trusted Publishing mints one token per workflow run, so this is
        // where the growth happens — nothing else prunes them.
        ScopedTokens::purgeExpired(NuGetApiKey::SCOPE_PUSH);

        $token = ScopedTokens::mint($user, $identity->describe(), $scopes, self::MINTED_TTL_MINUTES);

        model(FeedApiKeyRuleModel::class)->insert([
            'identity_id'        => (int) $token->id,
            'feed_id'            => $feedId,
            'id_pattern'         => $publisher['id_pattern'],
            'can_create_package' => (bool) $publisher['can_create_package'],
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $publisherModel->touch((int) $publisher['id']);

        return $this->response->setStatusCode(201)->setJSON([
            'token'      => $token->raw_token,
            'expires_in' => self::MINTED_TTL_MINUTES * 60,
            'scope'      => implode(' ', $scopes),
        ]);
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/json')
            ->setBody(json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    }
}
