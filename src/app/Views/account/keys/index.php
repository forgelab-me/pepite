<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>My API keys<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('account') ?>">&larr; My packages</a>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">My API keys</h1>
    <a class="btn btn-primary" href="<?= site_url('account/keys/create') ?>"><i class="bi bi-plus-lg me-1"></i>New key</a>
</div>

<?php if ($keys === []): ?>
    <p class="text-body-secondary">No key issued yet.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Feed</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($keys as $key): ?>
                    <tr>
                        <td><?= esc($key['name']) ?></td>
                        <td>
                            <?php if (empty($rules[$key['id']])): ?>
                                <span class="text-body-secondary">none</span>
                            <?php else: ?>
                                <?php foreach ($rules[$key['id']] as $rule): ?>
                                    <?= esc($rule['feed_name']) ?><br>
                                <?php endforeach ?>
                            <?php endif ?>
                        </td>
                        <td><?= esc($key['last_used_at'] ?? 'never') ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= site_url('account/keys/' . $key['id'] . '/revoke') ?>"
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
<?php endif ?>

<?= $this->endSection() ?>
