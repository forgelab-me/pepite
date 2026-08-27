<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Feeds — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Feeds</h1>
    <a class="btn btn-primary" href="<?= site_url('admin/feeds/create') ?>"><i class="bi bi-plus-lg me-1"></i>New feed</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
            <tr>
                <th>Feed</th><th>Visibility</th><th>New packages</th><th>Accepted types</th>
                <th>Packages</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($feeds as $feed): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages') ?>"><?= esc($feed['name']) ?></a>
                        <br><code class="text-body-secondary"><?= esc($feed['slug']) ?></code>
                    </td>
                    <td>
                        <span class="badge <?= $feed['visibility'] === 'private' ? 'text-bg-danger' : 'text-bg-success' ?>">
                            <?= $feed['visibility'] === 'private' ? 'private' : 'public' ?>
                        </span>
                    </td>
                    <td><?= $feed['allow_new_packages'] ? 'yes' : 'no' ?></td>
                    <td><?= esc($feed['allowed_package_types'] ? implode(', ', json_decode($feed['allowed_package_types'], true)) : 'all') ?></td>
                    <td><?= (int) $feed['package_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers') ?>">Publishers</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/feeds/' . $feed['id'] . '/edit') ?>">Edit</a>
                        <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/delete') ?>" class="d-inline"
                              onsubmit="return confirm('Delete feed &quot;<?= esc($feed['name'], 'js') ?>&quot; and all its packages?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
