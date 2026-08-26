<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>New admin — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/users') ?>">&larr; Admin accounts</a>
<h1>New admin account</h1>
<p class="lead">Give them the password directly — there's no guarantee this server can send e-mail.
    They can change it once logged in.</p>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<form method="post" action="<?= site_url('admin/users') ?>" class="card">
    <?= csrf_field() ?>

    <label>E-mail<input type="email" name="email" value="<?= esc(old('email') ?? '') ?>" required></label>
    <label>Username (optional — derived from the e-mail if left blank)<input type="text" name="username" value="<?= esc(old('username') ?? '') ?>"></label>
    <label>Password (8+ characters)<input type="password" name="password" required autocomplete="new-password"></label>
    <label>Confirm password<input type="password" name="password_confirm" required autocomplete="new-password"></label>

    <p style="margin-top:1.25rem;"><button type="submit" class="primary">Create admin</button></p>
</form>

<?= $this->endSection() ?>
