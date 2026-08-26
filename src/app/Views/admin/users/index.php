<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Admins — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="toolbar">
    <h1 style="margin:0;">Admin accounts</h1>
    <a class="button primary" href="<?= site_url('admin/users/create') ?>">New admin</a>
</div>

<div class="card">
    <table>
        <thead><tr><th>Username</th><th>E-mail</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $admin): ?>
            <tr>
                <td>
                    <?= esc($admin['username']) ?>
                    <?php if ((int) $admin['id'] === $currentUserId): ?>
                        <span class="badge">you</span>
                    <?php endif ?>
                </td>
                <td><?= esc($admin['email']) ?></td>
                <td>
                    <?php if ((int) $admin['active'] === 1): ?>
                        <span class="badge ok">active</span>
                    <?php else: ?>
                        <span class="badge warn">inactive</span>
                    <?php endif ?>
                </td>
                <td class="actions">
                    <?php if ((int) $admin['id'] !== $currentUserId): ?>
                        <form method="post" action="<?= site_url('admin/users/' . $admin['id'] . '/delete') ?>"
                              onsubmit="return confirm('Remove admin access for <?= esc($admin['email'], 'js') ?>?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="small danger">Remove access</button>
                        </form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
