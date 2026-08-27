<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($feed['name']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('/') ?>">&larr; Feeds</a>
<h1 class="h3"><?= esc($feed['name']) ?></h1>
<?php if (! empty($feed['description'])): ?><p class="text-body-secondary"><?= esc($feed['description']) ?></p><?php endif ?>

<form method="get" class="mb-3" style="max-width: 24rem;">
    <div class="input-group">
        <input type="search" class="form-control" name="q" value="<?= esc($query) ?>" placeholder="Search for a package">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
</form>

<?php if ($packages === []): ?>
    <p class="text-body-secondary">No matching package.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Package</th><th>Downloads</th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td>
                            <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $package['package_id_lower']) ?>">
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
