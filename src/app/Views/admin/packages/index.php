<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Packages — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Packages — <?= esc($feed['name']) ?></h1>

<?php if ($packages === []): ?>
    <p class="muted">No packages in this feed.</p>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Package</th><th>Downloads</th></tr></thead>
            <tbody>
            <?php foreach ($packages as $package): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages/' . $package['id']) ?>">
                            <?= esc($package['package_id']) ?>
                        </a>
                    </td>
                    <td><?= (int) $package['total_downloads'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
