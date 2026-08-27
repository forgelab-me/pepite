<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Edit <?= esc($feed['name']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1 class="h3">Edit &laquo;<?= esc($feed['name']) ?>&raquo;</h1>
<p class="text-body-secondary">Slug <code><?= esc($feed['slug']) ?></code> — not editable, it's used in the feed's URLs.</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><p class="mb-0"><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php
$types = $feed['allowed_package_types'] ? implode(', ', json_decode($feed['allowed_package_types'], true)) : '';
?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/feeds/' . $feed['id']) ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" type="text" id="name" name="name" value="<?= esc($feed['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="description">Description</label>
                <textarea class="form-control" id="description" name="description" rows="2"><?= esc($feed['description'] ?? '') ?></textarea>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="private" name="private" value="1"
                       <?= $feed['visibility'] === 'private' ? 'checked' : '' ?>>
                <label class="form-check-label" for="private">Private (read via API key)</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="no_new_packages" name="no_new_packages" value="1"
                       <?= ! $feed['allow_new_packages'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="no_new_packages">Refuse new identifiers</label>
            </div>
            <div class="mb-3">
                <label class="form-label" for="package_types">Accepted package types (comma-separated, empty = all)</label>
                <input class="form-control" type="text" id="package_types" name="package_types" value="<?= esc($types) ?>">
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
