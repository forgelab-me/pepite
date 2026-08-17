<?php

declare(strict_types=1);

namespace App\Controllers\V3;

use App\Libraries\V3\SearchBuilder;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * SearchQueryService and SearchAutocompleteService.
 *
 * Without these a client can still restore a package whose identifier it
 * already knows, but nothing can browse the feed.
 */
final class Search extends BaseV3Controller
{
    /**
     * nuget.org's own ceiling, and it keeps a hostile take= from walking the
     * whole feed in one request.
     */
    private const MAX_TAKE = 1000;

    public function query(string $slug): ResponseInterface
    {
        $feed = $this->feed($slug);

        if ($feed === null) {
            return $this->unknownFeed($slug);
        }

        $semVer2    = $this->wantsSemVer2();
        $prerelease = $this->boolQuery('prerelease', false);

        $result = model(PackageModel::class)->search(
            (int) $feed['id'],
            (string) ($this->request->getGet('q') ?? ''),
            $this->intQuery('skip', 0, 0, PHP_INT_MAX),
            $this->intQuery('take', 20, 0, self::MAX_TAKE),
            $prerelease,
            $semVer2,
            $this->packageType(),
        );

        $builder = new SearchBuilder($this->urls($slug), model(PackageVersionModel::class));

        return $this->json($builder->results($result['packages'], $result['total'], $prerelease, $semVer2));
    }

    /**
     * Two modes in one endpoint, as the protocol defines it: `id=` lists the
     * versions of one package, anything else completes identifiers.
     */
    public function autocomplete(string $slug): ResponseInterface
    {
        $feed = $this->feed($slug);

        if ($feed === null) {
            return $this->unknownFeed($slug);
        }

        $semVer2    = $this->wantsSemVer2();
        $prerelease = $this->boolQuery('prerelease', false);
        $builder    = new SearchBuilder($this->urls($slug), model(PackageVersionModel::class));

        $id = (string) ($this->request->getGet('id') ?? '');

        if ($id !== '') {
            $versions = $this->versionsOf($feed, $id, $prerelease, $semVer2);

            return $this->json($builder->autocomplete($versions, $versions === [] ? 0 : 1));
        }

        $result = model(PackageModel::class)->autocompleteIds(
            (int) $feed['id'],
            (string) ($this->request->getGet('q') ?? ''),
            $this->intQuery('skip', 0, 0, PHP_INT_MAX),
            $this->intQuery('take', 20, 0, self::MAX_TAKE),
            $prerelease,
            $semVer2,
            $this->packageType(),
        );

        return $this->json($builder->autocomplete($result['ids'], $result['total']));
    }

    /**
     * @param array<string, mixed> $feed
     *
     * @return list<string>
     */
    private function versionsOf(array $feed, string $id, bool $prerelease, bool $semVer2): array
    {
        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return [];
        }

        $versions = model(PackageVersionModel::class)->forPackage(
            (int) $package['id'],
            includeUnlisted: false,
            includePrerelease: $prerelease,
            semVer2: $semVer2,
        );

        return array_map(
            static fn (array $row): string => (string) $row['version_normalized'],
            $versions,
        );
    }

    /**
     * The standard filter that lets one server hold libraries and applications
     * without a client having to sort them out afterwards.
     */
    private function packageType(): ?string
    {
        $value = trim((string) ($this->request->getGet('packageType') ?? ''));

        return $value === '' ? null : $value;
    }
}
