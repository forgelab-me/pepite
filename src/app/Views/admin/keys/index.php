<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Clés API — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="toolbar">
    <h1 style="margin:0;">Clés API</h1>
    <a class="button primary" href="<?= site_url('admin/keys/create') ?>">Nouvelle clé</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Nom</th><th>Compte</th><th>Restrictions</th><th>Dernière utilisation</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $key): ?>
            <tr>
                <td><?= esc($key['name']) ?></td>
                <td><?= esc($key['username']) ?></td>
                <td>
                    <?php if (empty($rules[$key['id']])): ?>
                        <span class="muted">aucune</span>
                    <?php else: ?>
                        <?php foreach ($rules[$key['id']] as $rule): ?>
                            <code><?= esc($rule['id_pattern'] ?? '*') ?></code><?= $rule['can_create_package'] ? '' : ' (pas de création)' ?><br>
                        <?php endforeach ?>
                    <?php endif ?>
                </td>
                <td><?= esc($key['last_used_at'] ?? 'jamais') ?></td>
                <td class="actions">
                    <a class="button small" href="<?= site_url('admin/keys/' . $key['id'] . '/edit') ?>">Éditer</a>
                    <form method="post" action="<?= site_url('admin/keys/' . $key['id'] . '/revoke') ?>"
                          onsubmit="return confirm('Révoquer cette clé ?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="small danger">Révoquer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
