<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Nouvelle clé — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/keys') ?>">&larr; Clés API</a>
<h1>Nouvelle clé API</h1>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<form method="post" action="<?= site_url('admin/keys') ?>" class="card">
    <?= csrf_field() ?>

    <label>E-mail du compte<input type="email" name="email" required></label>
    <label>Nom de la clé<input type="text" name="name"></label>
    <label class="checkbox"><input type="checkbox" name="read_only" value="1"> Lecture seule</label>

    <label>Feed (optionnel, restreint la clé)
        <select name="feed">
            <option value="">— toute la portée —</option>
            <?php foreach ($feeds as $feed): ?>
                <option value="<?= esc($feed['slug']) ?>"><?= esc($feed['name']) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <label>Motif d'identifiant (glob, ex. Contoso.*)<input type="text" name="pattern"></label>
    <label class="checkbox"><input type="checkbox" name="no_create" value="1"> Ne peut pas créer de nouvel identifiant</label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Émettre</button></p>
</form>

<?= $this->endSection() ?>
