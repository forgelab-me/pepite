<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages') ?>">&larr; Packages — <?= esc($feed['name']) ?></a>
<h1><?= esc($package['package_id']) ?></h1>
<p class="lead">
    Owner(s):
    <?= $owners === [] ? 'none' : esc(implode(', ', $owners)) ?>
</p>

<div class="card">
    <table>
        <thead><tr><th>Version</th><th>Status</th><th>Downloads</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $version): ?>
            <tr>
                <td><?= esc($version['version_normalized']) ?></td>
                <td>
                    <span class="badge <?= $version['is_listed'] ? 'ok' : 'warn' ?>">
                        <?= $version['is_listed'] ? 'listed' : 'delisted' ?>
                    </span>
                </td>
                <td><?= (int) $version['downloads'] ?></td>
                <td class="actions">
                    <?php $base = 'admin/feeds/' . $feed['id'] . '/packages/' . $package['id'] . '/versions/' . $version['id']; ?>
                    <?php if ($version['is_listed']): ?>
                        <form method="post" action="<?= site_url($base . '/unlist') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="small">Delist</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= site_url($base . '/relist') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="small">Relist</button>
                        </form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<p class="muted" style="margin-top:1rem;">
    Delisting hides a version from search results without deleting it: a client that already
    depends on it keeps restoring it normally.
</p>

<?= $this->endSection() ?>
