<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use Forgelabme\TrustedPublishing\Jwks\CachedJwks;
use Forgelabme\TrustedPublishing\Providers\GithubActions;
use OpenSSLAsymmetricKey;

/**
 * A GitHub Actions OIDC issuer, entirely fake: an RSA keypair generated once
 * per test run, a JWKS built from its public half, and a signer for its
 * private half — so forgelab-me/ci4-trusted-publishing's real verification
 * path (signature, issuer, audience) can be exercised without ever reaching
 * GitHub.
 */
final class FakeGithubOidc
{
    private const KID = 'test-key-1';

    private static ?array $keypair = null;

    /**
     * Primes CachedJwks's cache with this fake issuer's public key, keyed
     * exactly as the package itself keys it, so verify() takes the
     * cache-hit path instead of trying to reach GitHub over the network.
     */
    public static function seedCache(): void
    {
        $key = CachedJwks::cacheKey(new GithubActions());
        service('cache')->save($key, self::jwks(), 60);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function token(array $claims): string
    {
        $defaults = [
            'iss' => GithubActions::ISSUER,
            'iat' => time(),
            'exp' => time() + 300,
        ];

        return JWT::encode($claims + $defaults, self::privateKey(), 'RS256', self::KID);
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    private static function jwks(): array
    {
        $details = openssl_pkey_get_details(self::keypair()['resource']);

        return ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'alg' => 'RS256',
            'use' => 'sig',
            'n'   => self::base64url($details['rsa']['n']),
            'e'   => self::base64url($details['rsa']['e']),
        ]]];
    }

    private static function privateKey(): string
    {
        return self::keypair()['private'];
    }

    /**
     * @return array{resource: OpenSSLAsymmetricKey, private: string}
     */
    private static function keypair(): array
    {
        if (self::$keypair !== null) {
            return self::$keypair;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $private);

        return self::$keypair = ['resource' => $resource, 'private' => $private];
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
