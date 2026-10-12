<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Audit log — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>
<h1 class="h3">Audit log</h1>
<p class="text-body-secondary">
    Deletions, delisting, API keys, admins, trusted publishers and feeds — newest first.
    Append-only: nothing in the app edits or removes an entry.
</p>

<?php if ($entries === []): ?>
    <p class="text-body-secondary">Nothing recorded yet.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="text-nowrap"><?= esc($entry['created_at']) ?></td>
                        <td>
                            <?= esc($entry['actor_label'] ?? '—') ?>
                            <span class="badge text-bg-secondary"><?= esc($entry['via']) ?></span>
                            <?php if (! empty($entry['ip'])): ?><span class="text-body-secondary small"><?= esc($entry['ip']) ?></span><?php endif ?>
                        </td>
                        <td><code><?= esc($entry['action']) ?></code></td>
                        <td>
                            <?= esc($entry['target'] ?? '') ?>
                            <?php if (! empty($entry['detail'])): ?>
                                <div class="text-body-secondary small"><code><?= esc($entry['detail']) ?></code></div>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-3">
        <?php if ($page > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/audit') ?>?page=<?= $page - 1 ?>">&larr; Newer</a>
        <?php else: ?>
            <span></span>
        <?php endif ?>
        <?php if ($hasMore): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('admin/audit') ?>?page=<?= $page + 1 ?>">Older &rarr;</a>
        <?php endif ?>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
