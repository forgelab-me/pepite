<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Nouveau feed — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Nouveau feed</h1>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<form method="post" action="<?= site_url('admin/feeds') ?>" class="card">
    <?= csrf_field() ?>

    <label>Slug<input type="text" name="slug" required></label>
    <label>Nom<input type="text" name="name" required></label>
    <label>Description<textarea name="description" rows="2"></textarea></label>
    <label class="checkbox"><input type="checkbox" name="private" value="1"> Privé (lecture par clé API)</label>
    <label class="checkbox"><input type="checkbox" name="no_new_packages" value="1"> Refuser les nouveaux identifiants</label>
    <label>Types de package acceptés (séparés par des virgules, vide = tous)
        <input type="text" name="package_types"></label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Créer</button></p>
</form>

<?= $this->endSection() ?>
