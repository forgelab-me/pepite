<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>New key<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('account/keys') ?>">&larr; My API keys</a>
<h1 class="h3">New API key</h1>
<p class="text-body-secondary">
    Scoped to push and delist your own packages on one feed. Need to push to
    another feed too? Issue a separate key for it.
</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><p class="mb-0"><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if ($feeds === []): ?>
    <p class="text-body-secondary">No feed currently accepts new packages from outside contributors.</p>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= site_url('account/keys') ?>">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label" for="name">Key name</label>
                    <input class="form-control" type="text" id="name" name="name" placeholder="My CI pipeline">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="feed">Feed</label>
                    <select class="form-select" id="feed" name="feed" required>
                        <?php foreach ($feeds as $feed): ?>
                            <option value="<?= esc($feed['slug']) ?>"><?= esc($feed['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Issue</button>
            </form>
        </div>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
