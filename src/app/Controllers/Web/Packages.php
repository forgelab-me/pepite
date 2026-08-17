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

        return $this->response->setBody(view('web/packages/show', [
            'feed'         => $feed,
            'package'      => $package,
            'version'      => $current,
            'versions'     => array_reverse($versions),
            'dependencies' => $dependencies,
            'readmeHtml'   => $readmeHtml,
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

    private function notFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody(view('errors/html/error_404', ['message' => 'Page introuvable']));
    }
}
