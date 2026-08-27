<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>API keys — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">API keys</h1>
    <a class="btn btn-primary" href="<?= site_url('admin/keys/create') ?>"><i class="bi bi-plus-lg me-1"></i>New key</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Name</th><th>Account</th><th>Restrictions</th><th>Last used</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($keys as $key): ?>
                <tr>
                    <td><?= esc($key['name']) ?></td>
                    <td><?= esc($key['username']) ?></td>
                    <td>
                        <?php if (empty($rules[$key['id']])): ?>
                            <span class="text-body-secondary">none</span>
                        <?php else: ?>
                            <?php foreach ($rules[$key['id']] as $rule): ?>
                                <code><?= esc($rule['id_pattern'] ?? '*') ?></code><?= $rule['can_create_package'] ? '' : ' (cannot create)' ?><br>
                            <?php endforeach ?>
                        <?php endif ?>
                    </td>
                    <td><?= esc($key['last_used_at'] ?? 'never') ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/keys/' . $key['id'] . '/edit') ?>">Edit</a>
                        <form method="post" action="<?= site_url('admin/keys/' . $key['id'] . '/revoke') ?>" class="d-inline"
                              onsubmit="return confirm('Revoke this key?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
