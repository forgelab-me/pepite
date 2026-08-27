<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>New key — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/keys') ?>">&larr; API keys</a>
<h1 class="h3">New API key</h1>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><p class="mb-0"><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/keys') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="email">Account e-mail</label>
                <input class="form-control" type="email" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="name">Key name</label>
                <input class="form-control" type="text" id="name" name="name">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="read_only" name="read_only" value="1">
                <label class="form-check-label" for="read_only">Read-only</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="feed">Feed (optional, restricts the key)</label>
                <select class="form-select" id="feed" name="feed">
                    <option value="">— unrestricted —</option>
                    <?php foreach ($feeds as $feed): ?>
                        <option value="<?= esc($feed['slug']) ?>"><?= esc($feed['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="pattern">Identifier pattern (glob, e.g. Contoso.*)</label>
                <input class="form-control" type="text" id="pattern" name="pattern">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="no_create" name="no_create" value="1">
                <label class="form-check-label" for="no_create">Cannot create a new identifier</label>
            </div>

            <button type="submit" class="btn btn-primary">Issue</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
