<?php

declare(strict_types=1);

namespace App\Libraries\V3;

use App\Models\PackageVersionModel;

/**
 * Builds SearchQueryService and SearchAutocompleteService responses.
 *
 * Shapes copied from live nuget.org responses. Two details that are easy to
 * get wrong and that clients do read: `@context.@base` must point at the
 * registration base actually in use, and every entry carries both the latest
 * version at the top level and the full `versions` list underneath.
 */
final class SearchBuilder
{
    public function __construct(
        private readonly FeedUrls $urls,
        private readonly PackageVersionModel $versions,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $packages
     *
     * @return array<string, mixed>
     */
    public function results(array $packages, int $totalHits, bool $includePrerelease, bool $semVer2): array
    {
        $data = [];

        foreach ($packages as $package) {
            $entry = $this->entry($package, $includePrerelease, $semVer2);

            if ($entry !== null) {
                $data[] = $entry;
            }
        }

        return [
            '@context' => [
                '@vocab' => 'http://schema.nuget.org/schema#',
                '@base'  => $this->urls->registrationBase($semVer2),
            ],
            'totalHits' => $totalHits,
            'data'      => $data,
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    public function autocomplete(array $values, int $totalHits): array
    {
        return [
            '@context'  => ['@vocab' => 'http://schema.nuget.org/schema#'],
            'totalHits' => $totalHits,
            'data'      => $values,
        ];
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, mixed>|null
     */
    private function entry(array $package, bool $includePrerelease, bool $semVer2): ?array
    {
        $packageRowId = (int) $package['id'];
        $idLower      = (string) $package['package_id_lower'];

        $all = $this->versions->forPackage(
            $packageRowId,
            includeUnlisted: false,
            includePrerelease: $includePrerelease,
            semVer2: $semVer2,
        );

        if ($all === []) {
            return null;
        }

        // Already ordered by the database; the last one is the newest.
        $latest = $all[count($all) - 1];

        $registration = $this->urls->registrationIndex($idLower, $semVer2);

        return [
            '@id'          => $registration,
            '@type'        => 'Package',
            'registration' => $registration,

            'id'          => (string) $package['package_id'],
            'version'     => (string) $latest['version_normalized'],
            'description' => (string) ($latest['description'] ?? ''),
            'summary'     => (string) ($latest['summary'] ?? ''),
            'title'       => (string) ($latest['title'] ?? ''),
            'iconUrl'     => $latest['icon_path'] !== null
                ? $this->urls->icon($idLower, (string) $latest['version_normalized_lower'])
                : (string) ($latest['icon_url'] ?? ''),
            'licenseUrl'     => (string) ($latest['license_url'] ?? ''),
            'projectUrl'     => (string) ($latest['project_url'] ?? ''),
            'tags'           => $this->decodeList($latest['tags'] ?? null),
            'authors'        => $this->decodeList($latest['authors'] ?? null),
            'owners'         => $this->decodeList($latest['owners'] ?? null),
            'totalDownloads' => (int) $package['total_downloads'],
            'verified'       => false,
            'packageTypes'   => $this->packageTypes($latest),

            'versions' => array_map(
                fn (array $version): array => [
                    '@id' => $this->urls->registrationLeaf(
                        $idLower,
                        (string) $version['version_normalized_lower'],
                        $semVer2,
                    ),
                    'version'   => (string) $version['version_normalized'],
                    'downloads' => (int) $version['downloads'],
                ],
                $all,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $version
     *
     * @return list<array{name: string}>
     */
    private function packageTypes(array $version): array
    {
        $decoded = json_decode((string) ($version['package_types'] ?? ''), true);

        if (! is_array($decoded) || $decoded === []) {
            return [['name' => 'Dependency']];
        }

        return array_values(array_map(
            static fn (array $type): array => ['name' => (string) $type['name']],
            $decoded,
        ));
    }

    /**
     * @return list<string>
     */
    private function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }
}
