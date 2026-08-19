<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Feeds — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/updates') ?>">&larr; Admin</a>

<div class="toolbar">
    <h1 style="margin:0;">Feeds</h1>
    <a class="button primary" href="<?= site_url('admin/feeds/create') ?>">New feed</a>
</div>

<div class="card">
    <table>
        <thead>
        <tr>
            <th>Feed</th><th>Visibility</th><th>New packages</th><th>Accepted types</th>
            <th>Packages</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($feeds as $feed): ?>
            <tr>
                <td>
                    <a href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages') ?>"><?= esc($feed['name']) ?></a>
                    <br><code class="muted"><?= esc($feed['slug']) ?></code>
                </td>
                <td>
                    <span class="badge <?= $feed['visibility'] === 'private' ? 'warn' : 'ok' ?>">
                        <?= $feed['visibility'] === 'private' ? 'private' : 'public' ?>
                    </span>
                </td>
                <td><?= $feed['allow_new_packages'] ? 'yes' : 'no' ?></td>
                <td><?= esc($feed['allowed_package_types'] ? implode(', ', json_decode($feed['allowed_package_types'], true)) : 'all') ?></td>
                <td><?= (int) $feed['package_count'] ?></td>
                <td class="actions">
                    <a class="button small" href="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers') ?>">Publishers</a>
                    <a class="button small" href="<?= site_url('admin/feeds/' . $feed['id'] . '/edit') ?>">Edit</a>
                    <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/delete') ?>"
                          onsubmit="return confirm('Delete feed &quot;<?= esc($feed['name'], 'js') ?>&quot; and all its packages?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="small danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
