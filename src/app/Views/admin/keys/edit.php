<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Éditer <?= esc($identity['name']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/keys') ?>">&larr; Clés API</a>
<h1>Éditer « <?= esc($identity['name']) ?> »</h1>
<p class="lead">
    La clé elle-même ne peut pas être réaffichée — seul son hachage est conservé. Ce formulaire
    modifie ses droits, pas sa valeur.
</p>

<form method="post" action="<?= site_url('admin/keys/' . $identity['id']) ?>" class="card">
    <?= csrf_field() ?>

    <label class="checkbox">
        <input type="checkbox" name="read_only" value="1" <?= in_array('packages.push', $scopes, true) ? '' : 'checked' ?>>
        Lecture seule
    </label>

    <label>Feed (optionnel, restreint la clé)
        <select name="feed">
            <option value="">— toute la portée —</option>
            <?php foreach ($feeds as $feed): ?>
                <option value="<?= esc($feed['slug']) ?>" <?= ($rule['feed_id'] ?? null) === $feed['id'] ? 'selected' : '' ?>>
                    <?= esc($feed['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </label>
    <label>Motif d'identifiant (glob, ex. Contoso.*)
        <input type="text" name="pattern" value="<?= esc($rule['id_pattern'] ?? '') ?>"></label>
    <label class="checkbox">
        <input type="checkbox" name="no_create" value="1" <?= isset($rule) && ! $rule['can_create_package'] ? 'checked' : '' ?>>
        Ne peut pas créer de nouvel identifiant
    </label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Enregistrer</button></p>
</form>

<?= $this->endSection() ?>
