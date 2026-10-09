<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FeedApiKeyRuleModel;
use App\Models\FeedModel;
use App\Models\PackageModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Models\UserIdentityModel;

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

        // Typed, like deleting a package: this removes every package in the
        // feed and every file behind them, and is at least as irreversible.
        if ((string) $this->request->getPost('confirm') !== $feed['slug']) {
            return redirect()->to(site_url('admin/feeds'))->with('error', 'Type the feed slug exactly to confirm its deletion.');
        }

        $this->revokeKeysConfinedTo($id);

        model(FeedModel::class)->delete($id);

        $directory = service('packageStorage')->absolute('packages/' . $id);

        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        return redirect()->to(site_url('admin/feeds'))->with('message', sprintf('Feed "%s" deleted.', $feed['slug']));
    }

    /**
     * A key's restriction is its feed_api_key_rules rows, and a key with no
     * rows at all is unrestricted (PublishAuthorizer, FeedRead). Those rows
     * cascade away with the feed — so a key confined to just this feed would
     * come out of its deletion able to push to, and read, every other feed.
     * Revoke those keys instead. One that still has a rule for another feed
     * (or for every feed) stays restricted and is left alone.
     */
    private function revokeKeysConfinedTo(int $feedId): void
    {
        $rules       = model(FeedApiKeyRuleModel::class);
        $identityIds = array_unique(array_map(
            static fn (array $rule): int => (int) $rule['identity_id'],
            $rules->where('feed_id', $feedId)->findAll(),
        ));

        foreach ($identityIds as $identityId) {
            $elsewhere = $rules->where('identity_id', $identityId)
                ->groupStart()->where('feed_id !=', $feedId)->orWhere('feed_id', null)->groupEnd()
                ->countAllResults();

            if ($elsewhere > 0) {
                continue;
            }

            model(UserIdentityModel::class)->where('id', $identityId)->where('type', 'access_token')->delete();
            $rules->where('identity_id', $identityId)->delete();
        }
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
