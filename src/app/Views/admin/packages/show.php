<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<?php $superadmin = auth()->user()->inGroup('superadmin'); ?>

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
                        <?php if ($superadmin): ?>
                            <form method="post" action="<?= site_url($base . '/purge') ?>"
                                  class="d-inline-flex gap-1 align-items-center ms-1"
                                  data-purge-form data-expect="<?= esc($package['package_id'], 'attr') ?>">
                                <?= csrf_field() ?>
                                <input type="text" name="confirm" class="form-control form-control-sm" style="width: 9rem;"
                                       placeholder="package id" autocomplete="off" data-purge-input required>
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-purge-submit disabled>Delete</button>
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

<?php if ($superadmin): ?>
    <div class="card border-danger mt-3">
        <div class="card-header text-danger">Danger zone</div>
        <div class="card-body">
            <p class="mb-3">
                Permanently deletes this package — every version, every stored file, gone.
                Unlike delisting, this cannot be undone, and anything already depending on it
                breaks.
            </p>
            <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/packages/' . $package['id'] . '/purge') ?>"
                  class="d-flex flex-wrap gap-2 align-items-center"
                  data-purge-form data-expect="<?= esc($package['package_id'], 'attr') ?>">
                <?= csrf_field() ?>
                <input type="text" name="confirm" class="form-control" style="max-width: 20rem;"
                       placeholder="Type &quot;<?= esc($package['package_id']) ?>&quot; to confirm" autocomplete="off"
                       data-purge-input required>
                <button type="submit" class="btn btn-danger" data-purge-submit disabled>Delete package permanently</button>
            </form>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.querySelectorAll('[data-purge-form]').forEach(function (form) {
    var input  = form.querySelector('[data-purge-input]');
    var button = form.querySelector('[data-purge-submit]');
    var expect = form.dataset.expect;

    input.addEventListener('input', function () {
        button.disabled = input.value !== expect;
    });
});
</script>
<?= $this->endSection() ?>
