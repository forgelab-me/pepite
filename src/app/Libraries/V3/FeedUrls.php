<?php

declare(strict_types=1);

namespace App\Libraries\V3;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Builds every absolute URL the V3 protocol has to advertise.
 *
 * Absolute is not optional. A client that authenticates against a private feed
 * only sends its credentials to the host it was pointed at; if the service
 * index answers with URLs on a different host or scheme, the credentials do
 * not follow and the restore dies on an opaque 401.
 *
 * The scheme and authority therefore come from the *incoming request*, not
 * from the configured base URL — a misconfigured app.baseURL must not be able
 * to break authentication. Only the path prefix is taken from the
 * configuration, so that an install in a subdirectory still works.
 */
final class FeedUrls
{
    private function __construct(
        private readonly string $base,
        private readonly string $slug,
    ) {
    }

    public static function fromRequest(IncomingRequest $request, string $slug): self
    {
        $uri = $request->getUri();

        $configuredPath = (string) (parse_url(config('App')->baseURL, PHP_URL_PATH) ?? '');
        $prefix         = rtrim($configuredPath, '/');

        return new self(
            $uri->getScheme() . '://' . $uri->getAuthority() . $prefix,
            $slug,
        );
    }

    public static function fromBase(string $base, string $slug): self
    {
        return new self(rtrim($base, '/'), $slug);
    }

    public function serviceIndex(): string
    {
        return $this->feed('/v3/index.json');
    }

    public function flatContainerBase(): string
    {
        return $this->feed('/v3/flatcontainer/');
    }

    public function packageVersions(string $idLower): string
    {
        return $this->feed(sprintf('/v3/flatcontainer/%s/index.json', $idLower));
    }

    public function packageContent(string $idLower, string $versionLower): string
    {
        return $this->feed(sprintf(
            '/v3/flatcontainer/%s/%s/%s.%s.nupkg',
            $idLower,
            $versionLower,
            $idLower,
            $versionLower,
        ));
    }

    public function nuspec(string $idLower, string $versionLower): string
    {
        return $this->feed(sprintf('/v3/flatcontainer/%s/%s/%s.nuspec', $idLower, $versionLower, $idLower));
    }

    /**
     * The icon lives under the flat container, exactly as nuget.org serves it:
     * a search response has to expose an absolute iconUrl, and the icon itself
     * only exists inside the archive until it is extracted at publish time.
     */
    public function icon(string $idLower, string $versionLower): string
    {
        return $this->feed(sprintf('/v3/flatcontainer/%s/%s/icon', $idLower, $versionLower));
    }

    public function readme(string $idLower, string $versionLower): string
    {
        return $this->feed(sprintf('/v3/flatcontainer/%s/%s/readme', $idLower, $versionLower));
    }

    /**
     * SemVer 2 filtering in registration is not a query parameter: nuget.org
     * publishes two different base URLs and lets the client pick the one it
     * understands. We mirror that.
     */
    public function registrationBase(bool $semVer2): string
    {
        return $this->feed($semVer2 ? '/v3/registration-semver2/' : '/v3/registration/');
    }

    public function registrationIndex(string $idLower, bool $semVer2): string
    {
        return $this->registrationBase($semVer2) . $idLower . '/index.json';
    }

    public function registrationLeaf(string $idLower, string $versionLower, bool $semVer2): string
    {
        return $this->registrationBase($semVer2) . $idLower . '/' . $versionLower . '.json';
    }

    public function search(): string
    {
        return $this->feed('/v3/search');
    }

    public function autocomplete(): string
    {
        return $this->feed('/v3/autocomplete');
    }

    public function publish(): string
    {
        return $this->feed('/api/v2/package');
    }

    public function symbolPublish(): string
    {
        return $this->feed('/api/v2/symbolpackage');
    }

    public function packageDetailsTemplate(): string
    {
        return $this->feed('/packages/{id}/{version}');
    }

    private function feed(string $path): string
    {
        return $this->base . '/feeds/' . $this->slug . $path;
    }
}
