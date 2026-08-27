<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Edit <?= esc($identity['name']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/keys') ?>">&larr; API keys</a>
<h1 class="h3">Edit &laquo;<?= esc($identity['name']) ?>&raquo;</h1>
<p class="text-body-secondary">
    The key itself cannot be shown again — only its hash is kept. This form changes its
    permissions, not its value.
</p>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/keys/' . $identity['id']) ?>">
            <?= csrf_field() ?>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="read_only" name="read_only" value="1"
                       <?= in_array('packages.push', $scopes, true) ? '' : 'checked' ?>>
                <label class="form-check-label" for="read_only">Read-only</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="feed">Feed (optional, restricts the key)</label>
                <select class="form-select" id="feed" name="feed">
                    <option value="">— unrestricted —</option>
                    <?php foreach ($feeds as $feed): ?>
                        <option value="<?= esc($feed['slug']) ?>" <?= ($rule['feed_id'] ?? null) === $feed['id'] ? 'selected' : '' ?>>
                            <?= esc($feed['name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="pattern">Identifier pattern (glob, e.g. Contoso.*)</label>
                <input class="form-control" type="text" id="pattern" name="pattern" value="<?= esc($rule['id_pattern'] ?? '') ?>">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="no_create" name="no_create" value="1"
                       <?= isset($rule) && ! $rule['can_create_package'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="no_create">Cannot create a new identifier</label>
            </div>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
