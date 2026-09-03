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
                <thead><tr><th>Package</th><th>Feed</th><th>Downloads</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td>
                            <a href="<?= site_url('account/packages/' . $package['id']) ?>"><?= esc($package['package_id']) ?></a>
                            <?php if ($package['feed_visibility'] === 'public'): ?>
                                <a class="ms-1 text-body-secondary" href="<?= site_url('browse/' . $package['feed_slug'] . '/' . $package['package_id_lower']) ?>" title="Public page">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif ?>
                        </td>
                        <td><?= esc($package['feed_name']) ?></td>
                        <td><?= (int) $package['total_downloads'] ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('account/packages/' . $package['id']) ?>">Manage</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
