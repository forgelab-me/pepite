<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Installation — Pépite</title>
<style>
    body { font: 16px/1.5 system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; }
    .ok { color: #2a7a2a; } .bad { color: #c0392b; }
    label { display:block; margin: .75rem 0 .25rem; }
    input, select { width: 100%; padding: .4rem; box-sizing: border-box; }
    fieldset { margin-top: 1.5rem; }
    .errors { border: 1px solid #c0392b; padding: .75rem 1rem; margin: 1rem 0; }
</style>
</head>
<body>

<h1>Installation de Pépite</h1>

<h2>Prérequis</h2>
<ul>
<?php foreach ($requirements as $req): ?>
    <li class="<?= $req['ok'] ? 'ok' : 'bad' ?>"><?= $req['ok'] ? '✓' : '✗' ?> <?= esc($req['label']) ?></li>
<?php endforeach ?>
</ul>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $e): ?><p><?= esc($e) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if (! in_array(false, array_column($requirements, 'ok'), true)): ?>
<form method="post" action="<?= site_url('install') ?>">
    <?= csrf_field() ?>

    <fieldset>
        <legend>Site</legend>
        <label>URL du site<input type="url" name="base_url" value="<?= esc($old['base_url'] ?? $baseUrl) ?>" required></label>
    </fieldset>

    <fieldset>
        <legend>Base de données</legend>
        <label>Moteur
            <select name="db_driver" id="db_driver" onchange="document.getElementById('mysql-fields').style.display=this.value==='MySQLi'?'block':'none'">
                <option value="MySQLi" <?= ($old['db_driver'] ?? 'MySQLi') === 'MySQLi' ? 'selected' : '' ?>>MySQL / MariaDB</option>
                <option value="SQLite3" <?= ($old['db_driver'] ?? '') === 'SQLite3' ? 'selected' : '' ?>>SQLite</option>
            </select>
        </label>
        <div id="mysql-fields">
            <label>Hôte<input type="text" name="db_hostname" value="<?= esc($old['db_hostname'] ?? 'localhost') ?>"></label>
            <label>Port<input type="number" name="db_port" value="<?= esc($old['db_port'] ?? '3306') ?>"></label>
            <label>Utilisateur<input type="text" name="db_username" value="<?= esc($old['db_username'] ?? '') ?>"></label>
            <label>Mot de passe<input type="password" name="db_password"></label>
        </div>
        <label>Nom de la base (ou chemin du fichier SQLite)<input type="text" name="db_database" value="<?= esc($old['db_database'] ?? '') ?>"></label>
    </fieldset>

    <fieldset>
        <legend>Compte administrateur</legend>
        <label>E-mail<input type="email" name="admin_email" value="<?= esc($old['admin_email'] ?? '') ?>" required></label>
        <label>Nom d'utilisateur<input type="text" name="admin_username" value="<?= esc($old['admin_username'] ?? '') ?>" required></label>
        <label>Mot de passe (8 caractères minimum)<input type="password" name="admin_password" required></label>
    </fieldset>

    <p style="margin-top:1.5rem;"><button type="submit">Installer</button></p>
</form>
<?php endif ?>

</body>
</html>
