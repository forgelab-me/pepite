<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages') ?>">&larr; Packages — <?= esc($feed['name']) ?></a>
<h1 class="h3"><?= esc($package['package_id']) ?></h1>
<p class="text-body-secondary">
    Owner(s):
    <?= $owners === [] ? 'none' : esc(implode(', ', $owners)) ?>
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Version</th><th>Status</th><th>Downloads</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($versions as $version): ?>
                <tr>
                    <td><?= esc($version['version_normalized']) ?></td>
                    <td>
                        <span class="badge <?= $version['is_listed'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                            <?= $version['is_listed'] ? 'listed' : 'delisted' ?>
                        </span>
                    </td>
                    <td><?= (int) $version['downloads'] ?></td>
                    <td class="text-end">
                        <?php $base = 'admin/feeds/' . $feed['id'] . '/packages/' . $package['id'] . '/versions/' . $version['id']; ?>
                        <?php if ($version['is_listed']): ?>
                            <form method="post" action="<?= site_url($base . '/unlist') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Delist</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= site_url($base . '/relist') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Relist</button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-body-secondary mt-3">
    Delisting hides a version from search results without deleting it: a client that already
    depends on it keeps restoring it normally.
</p>

<?= $this->endSection() ?>
