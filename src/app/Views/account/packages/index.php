<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>My packages<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">My packages</h1>
    <a class="btn btn-primary" href="<?= site_url('account/keys') ?>"><i class="bi bi-key me-1"></i>API keys</a>
</div>

<?php if ($packages === []): ?>
    <p class="text-body-secondary">No package pushed under this account yet — issue an API key and push one.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Package</th><th>Feed</th><th>Downloads</th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td>
                            <?php if ($package['feed_visibility'] === 'public'): ?>
                                <a href="<?= site_url('browse/' . $package['feed_slug'] . '/' . $package['package_id_lower']) ?>"><?= esc($package['package_id']) ?></a>
                            <?php else: ?>
                                <?= esc($package['package_id']) ?>
                            <?php endif ?>
                        </td>
                        <td><?= esc($package['feed_name']) ?></td>
                        <td><?= (int) $package['total_downloads'] ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
