<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($feed['name']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<p><a href="<?= site_url('/') ?>">&larr; Feeds</a></p>
<h1><?= esc($feed['name']) ?></h1>
<?php if (! empty($feed['description'])): ?><p><?= esc($feed['description']) ?></p><?php endif ?>

<form method="get" style="margin: 1rem 0;">
    <input type="search" name="q" value="<?= esc($query) ?>" placeholder="Search for a package"
           style="padding:.4rem .6rem; width: 20rem; max-width: 100%;">
    <button type="submit">Search</button>
</form>

<?php if ($packages === []): ?>
    <p>No matching package.</p>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Package</th><th>Downloads</th></tr></thead>
            <tbody>
            <?php foreach ($packages as $package): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $package['package_id_lower']) ?>">
                            <?= esc($package['package_id']) ?>
                        </a>
                    </td>
                    <td><?= (int) $package['total_downloads'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
