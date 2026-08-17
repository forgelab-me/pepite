<?php

declare(strict_types=1);

namespace App\Controllers\V3;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * The entry point every client starts from.
 *
 * Each capability is advertised under several @var aliases, which is not
 * redundancy: nuget.org does the same, because older clients look up older
 * type names and simply do not see a resource advertised only under the
 * newest one.
 */
final class ServiceIndex extends BaseV3Controller
{
    public function show(string $slug): ResponseInterface
    {
        if ($this->feed($slug) === null) {
            return $this->unknownFeed($slug);
        }

        $urls = $this->urls($slug);

        return $this->json([
            'version'   => '3.0.0',
            'resources' => [
                ...$this->resource($urls->flatContainerBase(), ['PackageBaseAddress/3.0.0'], 'Base URL of where NuGet packages are stored.'),

                // Two registration base URLs, differing only in whether they
                // expose SemVer 2 versions. This is how the protocol filters
                // them — there is no query parameter for it here.
                ...$this->resource($urls->registrationBase(false), [
                    'RegistrationsBaseUrl',
                    'RegistrationsBaseUrl/3.0.0-beta',
                    'RegistrationsBaseUrl/3.0.0-rc',
                ], 'Base URL of package metadata, SemVer 1 only.'),

                ...$this->resource($urls->registrationBase(true), [
                    'RegistrationsBaseUrl/3.4.0',
                    'RegistrationsBaseUrl/3.6.0',
                    'RegistrationsBaseUrl/Versioned',
                ], 'Base URL of package metadata, including SemVer 2 versions.'),

                ...$this->resource($urls->search(), [
                    'SearchQueryService',
                    'SearchQueryService/3.0.0-beta',
                    'SearchQueryService/3.0.0-rc',
                    'SearchQueryService/3.5.0',
                ], 'Query endpoint of the search service.'),

                ...$this->resource($urls->autocomplete(), [
                    'SearchAutocompleteService',
                    'SearchAutocompleteService/3.0.0-beta',
                    'SearchAutocompleteService/3.0.0-rc',
                    'SearchAutocompleteService/3.5.0',
                ], 'Autocomplete endpoint of the search service.'),

                ...$this->resource($urls->publish(), ['PackagePublish/2.0.0'], 'Package publish endpoint.'),

                // Advertised so that `dotnet nuget push` does not fail when a
                // .snupkg sits next to the package. Symbols are stored, not
                // served: see PLAN.md §4.4.
                ...$this->resource($urls->symbolPublish(), ['SymbolPackagePublish/4.9.0'], 'Symbol package publish endpoint.'),
            ],
            '@context' => [
                '@vocab'  => 'http://schema.nuget.org/services#',
                'comment' => 'http://www.w3.org/2000/01/rdf-schema#comment',
            ],
        ]);
    }

    /**
     * @param list<string> $types
     *
     * @return list<array<string, string>>
     */
    private function resource(string $id, array $types, string $comment): array
    {
        return array_map(
            static fn (string $type): array => ['@id' => $id, '@type' => $type, 'comment' => $comment],
            $types,
        );
    }
}
