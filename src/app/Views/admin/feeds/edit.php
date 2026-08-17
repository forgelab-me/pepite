<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Éditer <?= esc($feed['name']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Éditer « <?= esc($feed['name']) ?> »</h1>
<p class="lead">Slug <code><?= esc($feed['slug']) ?></code> — non modifiable, il est utilisé dans les URL du feed.</p>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php
$types = $feed['allowed_package_types'] ? implode(', ', json_decode($feed['allowed_package_types'], true)) : '';
?>

<form method="post" action="<?= site_url('admin/feeds/' . $feed['id']) ?>" class="card">
    <?= csrf_field() ?>

    <label>Nom<input type="text" name="name" value="<?= esc($feed['name']) ?>" required></label>
    <label>Description<textarea name="description" rows="2"><?= esc($feed['description'] ?? '') ?></textarea></label>
    <label class="checkbox">
        <input type="checkbox" name="private" value="1" <?= $feed['visibility'] === 'private' ? 'checked' : '' ?>>
        Privé (lecture par clé API)
    </label>
    <label class="checkbox">
        <input type="checkbox" name="no_new_packages" value="1" <?= ! $feed['allow_new_packages'] ? 'checked' : '' ?>>
        Refuser les nouveaux identifiants
    </label>
    <label>Types de package acceptés (séparés par des virgules, vide = tous)
        <input type="text" name="package_types" value="<?= esc($types) ?>"></label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Enregistrer</button></p>
</form>

<?= $this->endSection() ?>
