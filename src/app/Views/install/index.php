<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — Pépite</title>
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<style>
    /* Same palette as layout/main.php, kept standalone here: the installer
       runs before any config an admin could reach depends on, and it is
       used exactly once. */
    :root {
        --bs-primary: #a8791f; --bs-primary-rgb: 168, 121, 31;
        --bs-body-bg: #fbfaf7; --bs-body-color: #1c1a17;
        --bs-border-color: #e3ded4; --bs-secondary-color: #6b6459;
        --bs-card-bg: #fff; --bs-card-border-color: var(--bs-border-color);
        --bs-link-color: var(--bs-primary); --bs-link-hover-color: var(--bs-primary);
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bs-primary: #5b9cf6; --bs-primary-rgb: 91, 156, 246;
            --bs-body-bg: #14171c; --bs-body-color: #e6e9ee;
            --bs-border-color: #2b3037; --bs-secondary-color: #8c93a1;
            --bs-card-bg: #1b1f26;
        }
    }
    .btn-primary { background-color: var(--bs-primary); border-color: var(--bs-primary); }
</style>
</head>
<body>
<main class="container py-5" style="max-width: 40rem;">

<h1 class="h3 mb-4">Install Pépite</h1>

<div class="card mb-3">
    <div class="card-header">Requirements</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($requirements as $req): ?>
            <li class="list-group-item d-flex align-items-center gap-2">
                <?php if ($req['ok']): ?>
                    <i class="bi bi-check-circle-fill text-success"></i>
                <?php else: ?>
                    <i class="bi bi-x-circle-fill text-danger"></i>
                <?php endif ?>
                <?= esc($req['label']) ?>
            </li>
        <?php endforeach ?>
    </ul>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $e): ?><p class="mb-0"><?= esc($e) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if (! in_array(false, array_column($requirements, 'ok'), true)): ?>
<form method="post" action="<?= site_url('install') ?>">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-header">Site</div>
        <div class="card-body">
            <label class="form-label" for="base_url">Site URL</label>
            <input class="form-control" type="url" id="base_url" name="base_url" value="<?= esc($old['base_url'] ?? $baseUrl) ?>" required>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Database</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="db_driver">Engine</label>
                <select class="form-select" name="db_driver" id="db_driver"
                        onchange="document.getElementById('mysql-fields').style.display=this.value==='MySQLi'?'block':'none'">
                    <option value="MySQLi" <?= ($old['db_driver'] ?? 'MySQLi') === 'MySQLi' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                    <option value="SQLite3" <?= ($old['db_driver'] ?? '') === 'SQLite3' ? 'selected' : '' ?>>SQLite</option>
                </select>
            </div>
            <div id="mysql-fields">
                <div class="mb-3">
                    <label class="form-label" for="db_hostname">Host</label>
                    <input class="form-control" type="text" id="db_hostname" name="db_hostname" value="<?= esc($old['db_hostname'] ?? 'localhost') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="db_port">Port</label>
                    <input class="form-control" type="number" id="db_port" name="db_port" value="<?= esc($old['db_port'] ?? '3306') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="db_username">Username</label>
                    <input class="form-control" type="text" id="db_username" name="db_username" value="<?= esc($old['db_username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="db_password">Password</label>
                    <input class="form-control" type="password" id="db_password" name="db_password">
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label" for="db_database">Database name (or SQLite file path)</label>
                <input class="form-control" type="text" id="db_database" name="db_database" value="<?= esc($old['db_database'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Administrator account</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="admin_email">E-mail</label>
                <input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= esc($old['admin_email'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="admin_username">Username</label>
                <input class="form-control" type="text" id="admin_username" name="admin_username" value="<?= esc($old['admin_username'] ?? '') ?>" required>
            </div>
            <div class="mb-0">
                <label class="form-label" for="admin_password">Password (8 characters minimum)</label>
                <input class="form-control" type="password" id="admin_password" name="admin_password" required>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Install</button>
</form>
<?php endif ?>

</main>
</body>
</html>
