<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Edit <?= esc($identity['name']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/keys') ?>">&larr; API keys</a>
<h1>Edit &laquo;<?= esc($identity['name']) ?>&raquo;</h1>
<p class="lead">
    The key itself cannot be shown again — only its hash is kept. This form changes its
    permissions, not its value.
</p>

<form method="post" action="<?= site_url('admin/keys/' . $identity['id']) ?>" class="card">
    <?= csrf_field() ?>

    <label class="checkbox">
        <input type="checkbox" name="read_only" value="1" <?= in_array('packages.push', $scopes, true) ? '' : 'checked' ?>>
        Read-only
    </label>

    <label>Feed (optional, restricts the key)
        <select name="feed">
            <option value="">— unrestricted —</option>
            <?php foreach ($feeds as $feed): ?>
                <option value="<?= esc($feed['slug']) ?>" <?= ($rule['feed_id'] ?? null) === $feed['id'] ? 'selected' : '' ?>>
                    <?= esc($feed['name']) ?>
                </option>
            <?php endforeach ?>
        </select>
    </label>
    <label>Identifier pattern (glob, e.g. Contoso.*)
        <input type="text" name="pattern" value="<?= esc($rule['id_pattern'] ?? '') ?>"></label>
    <label class="checkbox">
        <input type="checkbox" name="no_create" value="1" <?= isset($rule) && ! $rule['can_create_package'] ? 'checked' : '' ?>>
        Cannot create a new identifier
    </label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Save</button></p>
</form>

<?= $this->endSection() ?>
