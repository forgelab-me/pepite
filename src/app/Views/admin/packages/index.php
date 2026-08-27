<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Packages — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1 class="h3">Packages — <?= esc($feed['name']) ?></h1>

<?php if ($packages === []): ?>
    <p class="text-body-secondary">No packages in this feed.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
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
    </div>
<?php endif ?>

<?= $this->endSection() ?>
