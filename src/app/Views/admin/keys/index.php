<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>API keys — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="toolbar">
    <h1 style="margin:0;">API keys</h1>
    <a class="button primary" href="<?= site_url('admin/keys/create') ?>">New key</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Account</th><th>Restrictions</th><th>Last used</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($keys as $key): ?>
            <tr>
                <td><?= esc($key['name']) ?></td>
                <td><?= esc($key['username']) ?></td>
                <td>
                    <?php if (empty($rules[$key['id']])): ?>
                        <span class="muted">none</span>
                    <?php else: ?>
                        <?php foreach ($rules[$key['id']] as $rule): ?>
                            <code><?= esc($rule['id_pattern'] ?? '*') ?></code><?= $rule['can_create_package'] ? '' : ' (cannot create)' ?><br>
                        <?php endforeach ?>
                    <?php endif ?>
                </td>
                <td><?= esc($key['last_used_at'] ?? 'never') ?></td>
                <td class="actions">
                    <a class="button small" href="<?= site_url('admin/keys/' . $key['id'] . '/edit') ?>">Edit</a>
                    <form method="post" action="<?= site_url('admin/keys/' . $key['id'] . '/revoke') ?>"
                          onsubmit="return confirm('Revoke this key?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="small danger">Revoke</button>
                    </form>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
