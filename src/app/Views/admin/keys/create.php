<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>New key — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/keys') ?>">&larr; API keys</a>
<h1>New API key</h1>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<form method="post" action="<?= site_url('admin/keys') ?>" class="card">
    <?= csrf_field() ?>

    <label>Account e-mail<input type="email" name="email" required></label>
    <label>Key name<input type="text" name="name"></label>
    <label class="checkbox"><input type="checkbox" name="read_only" value="1"> Read-only</label>

    <label>Feed (optional, restricts the key)
        <select name="feed">
            <option value="">— unrestricted —</option>
            <?php foreach ($feeds as $feed): ?>
                <option value="<?= esc($feed['slug']) ?>"><?= esc($feed['name']) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <label>Identifier pattern (glob, e.g. Contoso.*)<input type="text" name="pattern"></label>
    <label class="checkbox"><input type="checkbox" name="no_create" value="1"> Cannot create a new identifier</label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Issue</button></p>
</form>

<?= $this->endSection() ?>
