<?php

declare(strict_types=1);

namespace App\Libraries\V3;

/**
 * Builds registration documents.
 *
 * This is the part of the protocol that decides whether `dotnet restore`
 * works. It is what the client reads to resolve dependencies, and an error in
 * its shape does not produce a helpful message: it produces NU1101 or NU1102,
 * which say nothing about what is actually wrong.
 *
 * The document layout below is copied from a live nuget.org response rather
 * than from prose documentation — including the details that look arbitrary,
 * like `minClientVersion` being an empty string instead of null, and the page
 * identifier carrying a `#page/{lower}/{upper}` fragment.
 *
 * Versions are inlined into a single page. Paging only earns its complexity
 * past a few hundred versions, and it changes nothing for the client: it
 * follows `items` either way.
 */
final class RegistrationBuilder
{
    /**
     * Same shape nuget.org serves. It is a JSON-LD context, so the client is
     * entitled to rely on it.
     */
    private const CONTEXT = [
        '@vocab'          => 'http://schema.nuget.org/schema#',
        'catalog'         => 'http://schema.nuget.org/catalog#',
        'xsd'             => 'http://www.w3.org/2001/XMLSchema#',
        'items'           => ['@id' => 'catalog:item', '@container' => '@set'],
        'commitTimeStamp' => ['@id' => 'catalog:commitTimeStamp', '@type' => 'xsd:dateTime'],
        'commitId'        => ['@id' => 'catalog:commitId'],
        'count'           => ['@id' => 'catalog:count'],
        'parent'          => ['@id' => 'catalog:parent', '@type' => '@id'],
        'tags'            => ['@id' => 'tag', '@container' => '@set'],
        'reasons'         => ['@container' => '@set'],

        'packageTargetFrameworks' => ['@id' => 'packageTargetFramework', '@container' => '@set'],
        'dependencyGroups'        => ['@id' => 'dependencyGroup', '@container' => '@set'],
        'dependencies'            => ['@id' => 'dependency', '@container' => '@set'],

        'packageContent' => ['@type' => '@id'],
        'published'      => ['@type' => 'xsd:dateTime'],
        'registration'   => ['@type' => '@id'],
    ];

    public function __construct(private readonly FeedUrls $urls)
    {
    }

    /**
     * @param array<string, mixed>                   $package
     * @param list<array<string, mixed>>             $versions     ascending
     * @param array<int, list<array<string, mixed>>> $dependencies keyed by version row id
     *
     * @return array<string, mixed>
     */
    public function index(array $package, array $versions, array $dependencies, bool $semVer2): array
    {
        $idLower  = (string) $package['package_id_lower'];
        $indexUrl = $this->urls->registrationIndex($idLower, $semVer2);

        $leaves = [];

        foreach ($versions as $version) {
            $leaves[] = $this->inlineLeaf(
                $package,
                $version,
                $dependencies[(int) $version['id']] ?? [],
                $semVer2,
            );
        }

        $lower = $versions === [] ? '' : (string) $versions[0]['version_normalized_lower'];
        $upper = $versions === [] ? '' : (string) $versions[count($versions) - 1]['version_normalized_lower'];

        $commit = $this->commit($versions);

        return [
            '@id'             => $indexUrl,
            '@type'           => ['catalog:CatalogRoot', 'PackageRegistration', 'catalog:Permalink'],
            'commitId'        => $commit['id'],
            'commitTimeStamp' => $commit['timestamp'],
            'count'           => $versions === [] ? 0 : 1,
            'items'           => $versions === [] ? [] : [[
                '@id'             => sprintf('%s#page/%s/%s', $indexUrl, $lower, $upper),
                '@type'           => 'catalog:CatalogPage',
                'commitId'        => $commit['id'],
                'commitTimeStamp' => $commit['timestamp'],
                'count'           => count($leaves),
                'items'           => $leaves,
                'parent'          => $indexUrl,
                'lower'           => $lower,
                'upper'           => $upper,
            ]],
            '@context' => self::CONTEXT,
        ];
    }

    /**
     * The standalone leaf document.
     *
     * A client that read an index with inlined catalogEntry objects never
     * fetches this. It exists for the ones that do, and it costs one route.
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $version
     *
     * @return array<string, mixed>
     */
    public function leaf(array $package, array $version, bool $semVer2): array
    {
        $idLower      = (string) $package['package_id_lower'];
        $versionLower = (string) $version['version_normalized_lower'];

        return [
            '@id'   => $this->urls->registrationLeaf($idLower, $versionLower, $semVer2),
            '@type' => ['Package', 'catalog:Permalink'],

            'catalogEntry'   => $this->urls->registrationLeaf($idLower, $versionLower, $semVer2),
            'listed'         => (bool) $version['is_listed'],
            'packageContent' => $this->urls->packageContent($idLower, $versionLower),
            'published'      => $this->published($version),
            'registration'   => $this->urls->registrationIndex($idLower, $semVer2),

            '@context' => [
                '@vocab'         => 'http://schema.nuget.org/schema#',
                'xsd'            => 'http://www.w3.org/2001/XMLSchema#',
                'catalogEntry'   => ['@type' => '@id'],
                'registration'   => ['@type' => '@id'],
                'packageContent' => ['@type' => '@id'],
                'published'      => ['@type' => 'xsd:dateTime'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>       $package
     * @param array<string, mixed>       $version
     * @param list<array<string, mixed>> $dependencies
     *
     * @return array<string, mixed>
     */
    private function inlineLeaf(array $package, array $version, array $dependencies, bool $semVer2): array
    {
        $idLower      = (string) $package['package_id_lower'];
        $versionLower = (string) $version['version_normalized_lower'];

        $leafUrl    = $this->urls->registrationLeaf($idLower, $versionLower, $semVer2);
        $contentUrl = $this->urls->packageContent($idLower, $versionLower);

        return [
            '@id'             => $leafUrl,
            '@type'           => 'Package',
            'commitId'        => $this->commitId($version),
            'commitTimeStamp' => $this->published($version),

            'catalogEntry' => [
                // With no catalog resource to point at, the leaf is the stable
                // permalink for this version's metadata.
                '@id'   => $leafUrl,
                '@type' => 'PackageDetails',

                'authors'          => $this->joinList($version['authors'] ?? null),
                'dependencyGroups' => $this->dependencyGroups($leafUrl, $dependencies, $semVer2),
                'description'      => (string) ($version['description'] ?? ''),
                'iconUrl'          => $version['icon_path'] !== null
                    ? $this->urls->icon($idLower, $versionLower)
                    : (string) ($version['icon_url'] ?? ''),
                'id'                => (string) $package['package_id'],
                'language'          => (string) ($version['language'] ?? ''),
                'licenseExpression' => (string) ($version['license_type'] === 'expression'
                    ? ($version['license_value'] ?? '')
                    : ''),
                'licenseUrl'               => (string) ($version['license_url'] ?? ''),
                'listed'                   => (bool) $version['is_listed'],
                'minClientVersion'         => (string) ($version['min_client_version'] ?? ''),
                'packageContent'           => $contentUrl,
                'projectUrl'               => (string) ($version['project_url'] ?? ''),
                'published'                => $this->published($version),
                'requireLicenseAcceptance' => (bool) $version['require_license_acceptance'],
                'summary'                  => (string) ($version['summary'] ?? ''),
                'tags'                     => $this->decodeList($version['tags'] ?? null),
                'title'                    => (string) ($version['title'] ?? ''),
                'version'                  => (string) $version['version_normalized'],
            ],

            'packageContent' => $contentUrl,
            'registration'   => $this->urls->registrationIndex($idLower, $semVer2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     *
     * @return list<array<string, mixed>>
     */
    private function dependencyGroups(string $leafUrl, array $dependencies, bool $semVer2): array
    {
        if ($dependencies === []) {
            return [];
        }

        $byFramework = [];

        foreach ($dependencies as $dependency) {
            $framework                 = (string) ($dependency['target_framework'] ?? '');
            $byFramework[$framework][] = $dependency;
        }

        $groups = [];

        foreach ($byFramework as $framework => $rows) {
            $groupId = $framework === ''
                ? $leafUrl . '#dependencygroup'
                : $leafUrl . '#dependencygroup/' . strtolower($framework);

            $group = [
                '@id'   => $groupId,
                '@type' => 'PackageDependencyGroup',
            ];

            if ($framework !== '') {
                $group['targetFramework'] = $framework;
            }

            $group['dependencies'] = array_map(
                fn (array $row): array => [
                    '@id'          => $groupId . '/' . strtolower((string) $row['dependency_id']),
                    '@type'        => 'PackageDependency',
                    'id'           => (string) $row['dependency_id'],
                    'range'        => (string) ($row['version_range'] ?? '(, )'),
                    'registration' => $this->urls->registrationIndex(
                        strtolower((string) $row['dependency_id']),
                        $semVer2,
                    ),
                ],
                $rows,
            );

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return array{id: string, timestamp: string}
     */
    private function commit(array $versions): array
    {
        $latest = $versions === [] ? null : $versions[count($versions) - 1];

        return [
            'id'        => $latest === null ? $this->commitId([]) : $this->commitId($latest),
            'timestamp' => $latest === null ? $this->published([]) : $this->published($latest),
        ];
    }

    /**
     * A stable identifier derived from the row rather than a random one, so
     * that an unchanged registration serves an unchanged document — which is
     * what makes ETag caching worth anything.
     *
     * @param array<string, mixed> $version
     */
    private function commitId(array $version): string
    {
        $seed = ($version['id'] ?? '0') . '|' . ($version['updated_at'] ?? $version['published_at'] ?? '');
        $hash = md5((string) $seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /**
     * @param array<string, mixed> $version
     */
    private function published(array $version): string
    {
        $value = $version['published_at'] ?? $version['created_at'] ?? null;

        $timestamp = $value === null ? 0 : (strtotime((string) $value) ?: 0);

        return gmdate('Y-m-d\TH:i:s.000\+00:00', $timestamp);
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

    private function joinList(?string $json): string
    {
        return implode(', ', $this->decodeList($json));
    }
}
