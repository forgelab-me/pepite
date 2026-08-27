<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>New admin — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/users') ?>">&larr; Admin accounts</a>
<h1 class="h3">New admin account</h1>
<p class="text-body-secondary">Give them the password directly — there's no guarantee this server can send e-mail.
    They can change it once logged in.</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><p class="mb-0"><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/users') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="email">E-mail</label>
                <input class="form-control" type="email" id="email" name="email" value="<?= esc(old('email') ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="username">Username (optional — derived from the e-mail if left blank)</label>
                <input class="form-control" type="text" id="username" name="username" value="<?= esc(old('username') ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password (8+ characters)</label>
                <input class="form-control" type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary">Create admin</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
