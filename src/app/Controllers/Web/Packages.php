<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Libraries\Markdown;
use App\Models\FeedModel;
use App\Models\PackageDependencyModel;
use App\Models\PackageModel;
use App\Models\PackageVersionModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

final class Packages extends Controller
{
    public function show(string $slug, string $id, ?string $version = null): ResponseInterface
    {
        $feed = model(FeedModel::class)->findBySlug($slug);

        if ($feed === null || $feed['visibility'] !== 'public') {
            return $this->notFound();
        }

        $package = model(PackageModel::class)->findInFeed((int) $feed['id'], $id);

        if ($package === null) {
            return $this->notFound();
        }

        $versions = model(PackageVersionModel::class)->forPackage(
            (int) $package['id'],
            includeUnlisted: false,
            includePrerelease: true,
        );

        if ($versions === []) {
            return $this->notFound();
        }

        $current = $version === null
            ? $versions[count($versions) - 1]
            : $this->findVersion($versions, $version);

        if ($current === null) {
            return $this->notFound();
        }

        $readmeHtml = null;

        if ($current['readme_path'] !== null) {
            $path = service('packageStorage')->absolute($current['readme_path']);

            if (is_file($path)) {
                $readmeHtml = Markdown::toHtml((string) file_get_contents($path));
            }
        }

        $dependencies = model(PackageDependencyModel::class)->forVersion((int) $current['id']);

        $usedBy = model(PackageDependencyModel::class)->usedBy(
            (int) $feed['id'],
            strtolower($package['package_id']),
        );

        return $this->response->setBody(view('web/packages/show', [
            'feed'                    => $feed,
            'package'                 => $package,
            'version'                 => $current,
            'versions'                => array_reverse($versions),
            'dependenciesByFramework' => $this->groupByFramework($dependencies),
            'readmeHtml'              => $readmeHtml,
            'tags'                    => $this->decodeList($current['tags'] ?? null),
            'authors'                 => $this->decodeList($current['authors'] ?? null),
            'owners'                  => $this->decodeList($current['owners'] ?? null),
            'iconUrl'                 => $this->iconUrl($feed['slug'], $package['package_id_lower'], $current),
            'usedBy'                  => $usedBy,
        ]));
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return array<string, mixed>|null
     */
    private function findVersion(array $versions, string $version): ?array
    {
        foreach ($versions as $row) {
            if ($row['version_normalized_lower'] === strtolower($version)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Groups a flat dependency list the way nuget.org's package page does:
     * one section per target framework, "Any framework" for a legacy
     * <dependencies> block with none declared.
     *
     * @param list<array<string, mixed>> $dependencies
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByFramework(array $dependencies): array
    {
        $groups = [];

        foreach ($dependencies as $dependency) {
            $framework        = $dependency['target_framework'] ?? '';
            $label            = $framework === '' || $framework === null ? 'Any framework' : $framework;
            $groups[$label][] = $dependency;
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $version
     */
    private function iconUrl(string $slug, string $idLower, array $version): ?string
    {
        if (! empty($version['icon_path'])) {
            return site_url(sprintf(
                'feeds/%s/v3/flatcontainer/%s/%s/icon',
                $slug,
                $idLower,
                $version['version_normalized_lower'],
            ));
        }

        return $version['icon_url'] ?? null ?: null;
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

    private function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404', ['message' => 'Page introuvable']));
    }
}
