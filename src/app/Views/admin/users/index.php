<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Admins — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">Admin accounts</h1>
    <a class="btn btn-primary" href="<?= site_url('admin/users/create') ?>"><i class="bi bi-plus-lg me-1"></i>New admin</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Username</th><th>E-mail</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td>
                        <?= esc($admin['username']) ?>
                        <?php if ((int) $admin['id'] === $currentUserId): ?>
                            <span class="badge text-bg-secondary">you</span>
                        <?php endif ?>
                    </td>
                    <td><?= esc($admin['email']) ?></td>
                    <td>
                        <?php if ((int) $admin['active'] === 1): ?>
                            <span class="badge text-bg-success">active</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger">inactive</span>
                        <?php endif ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ((int) $admin['id'] !== $currentUserId): ?>
                            <form method="post" action="<?= site_url('admin/users/' . $admin['id'] . '/delete') ?>"
                                  onsubmit="return confirm('Remove admin access for <?= esc($admin['email'], 'js') ?>?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Remove access</button>
                            </form>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
