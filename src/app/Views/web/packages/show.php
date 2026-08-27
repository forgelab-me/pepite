<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('browse/' . $feed['slug']) ?>">&larr; <?= esc($feed['name']) ?></a>

<h1 class="h3 mb-1">
    <?= esc($package['package_id']) ?>
    <small class="text-body-secondary fw-normal"><?= esc($version['version_normalized']) ?></small>
</h1>

<?php if (! empty($version['description'])): ?><p><?= esc($version['description']) ?></p><?php endif ?>

<p>
    <a class="btn btn-primary" href="<?= site_url('feeds/' . $feed['slug'] . '/v3/flatcontainer/' . $package['package_id_lower'] . '/' . $version['version_normalized_lower'] . '/' . $package['package_id_lower'] . '.' . $version['version_normalized_lower'] . '.nupkg') ?>">
        <i class="bi bi-download me-1"></i>Download the .nupkg
    </a>
</p>

<div class="card mb-3">
    <div class="card-header">Versions</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($versions as $row): ?>
            <li class="list-group-item">
                <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $package['package_id_lower'] . '/' . $row['version_normalized_lower']) ?>">
                    <?= esc($row['version_normalized']) ?>
                </a>
                <?= $row['id'] === $version['id'] ? '<span class="badge text-bg-secondary ms-2">current</span>' : '' ?>
            </li>
        <?php endforeach ?>
    </ul>
</div>

<?php if ($dependencies !== []): ?>
    <div class="card mb-3">
        <div class="card-header">Dependencies</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Framework</th><th>Package</th><th>Range</th></tr></thead>
                <tbody>
                <?php foreach ($dependencies as $dep): ?>
                    <tr>
                        <td><?= esc($dep['target_framework'] ?? 'any') ?></td>
                        <td><?= esc($dep['dependency_id']) ?></td>
                        <td><code><?= esc($dep['version_range'] ?? '(, )') ?></code></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<?php if ($readmeHtml !== null): ?>
    <div class="card">
        <div class="card-body">
            <?= $readmeHtml ?>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
