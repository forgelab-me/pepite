<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FeedModel;
use App\Models\PackageModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

final class Feeds extends Controller
{
    public function index(): ResponseInterface
    {
        $feeds = model(FeedModel::class)->orderBy('id', 'ASC')->findAll();

        foreach ($feeds as &$feed) {
            $feed['package_count'] = model(PackageModel::class)->where('feed_id', $feed['id'])->countAllResults();
        }

        return $this->response->setBody(view('admin/feeds/index', ['feeds' => $feeds]));
    }

    public function create(): ResponseInterface
    {
        return $this->response->setBody(view('admin/feeds/create', ['errors' => []]));
    }

    public function store(): ResponseInterface
    {
        $slug = strtolower(trim((string) $this->request->getPost('slug')));

        $rules = [
            'slug' => 'required|alpha_dash|min_length[2]|max_length[64]|is_unique[feeds.slug]',
            'name' => 'required|max_length[128]',
        ];

        if (! $this->validateData(['slug' => $slug, 'name' => (string) $this->request->getPost('name')], $rules)) {
            return $this->response->setBody(view('admin/feeds/create', [
                'errors' => $this->validator->getErrors(),
            ]));
        }

        model(FeedModel::class)->insert([
            'slug'                  => $slug,
            'name'                  => trim((string) $this->request->getPost('name')),
            'description'           => trim((string) $this->request->getPost('description')) ?: null,
            'visibility'            => $this->request->getPost('private') ? 'private' : 'public',
            'allow_new_packages'    => $this->request->getPost('no_new_packages') ? false : true,
            'allowed_package_types' => $this->packageTypesFromPost(),
        ]);

        return redirect()->to(site_url('admin/feeds'))->with('message', sprintf('Feed "%s" created.', $slug));
    }

    public function edit(int $id): ResponseInterface
    {
        $feed = $this->requireFeed($id);

        return $this->response->setBody(view('admin/feeds/edit', ['feed' => $feed, 'errors' => []]));
    }

    public function update(int $id): ResponseInterface
    {
        $feed = $this->requireFeed($id);

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            return $this->response->setBody(view('admin/feeds/edit', [
                'feed'   => $feed,
                'errors' => ['Name is required.'],
            ]));
        }

        model(FeedModel::class)->update($id, [
            'name'                  => $name,
            'description'           => trim((string) $this->request->getPost('description')) ?: null,
            'visibility'            => $this->request->getPost('private') ? 'private' : 'public',
            'allow_new_packages'    => $this->request->getPost('no_new_packages') ? false : true,
            'allowed_package_types' => $this->packageTypesFromPost(),
        ]);

        return redirect()->to(site_url('admin/feeds'))->with('message', sprintf('Feed "%s" updated.', $feed['slug']));
    }

    /**
     * Deletes the feed row — which cascades in the database to its packages,
     * versions, dependencies and ownership rows — then removes the blobs the
     * database cannot reach: everything under packages/{feedId}/ in storage.
     */
    public function destroy(int $id): ResponseInterface
    {
        $feed = $this->requireFeed($id);

        model(FeedModel::class)->delete($id);

        $directory = service('packageStorage')->absolute('packages/' . $id);

        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        return redirect()->to(site_url('admin/feeds'))->with('message', sprintf('Feed "%s" deleted.', $feed['slug']));
    }

    /**
     * @return array<string, mixed>
     */
    private function requireFeed(int $id): array
    {
        $feed = model(FeedModel::class)->find($id);

        if ($feed === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $feed;
    }

    private function packageTypesFromPost(): ?string
    {
        $types = trim((string) $this->request->getPost('package_types'));
        $list  = $types === '' ? [] : array_values(array_filter(array_map(trim(...), explode(',', $types))));

        return $list === [] ? null : json_encode($list);
    }

    private function removeDirectory(string $path): void
    {
        foreach ((array) glob($path . '/*') as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
