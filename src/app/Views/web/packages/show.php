<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<p><a href="<?= site_url('browse/' . $feed['slug']) ?>">&larr; <?= esc($feed['name']) ?></a></p>

<h1><?= esc($package['package_id']) ?> <small style="color: var(--muted);"><?= esc($version['version_normalized']) ?></small></h1>

<?php if (! empty($version['description'])): ?><p><?= esc($version['description']) ?></p><?php endif ?>

<p>
    <a class="button" href="<?= site_url('feeds/' . $feed['slug'] . '/v3/flatcontainer/' . $package['package_id_lower'] . '/' . $version['version_normalized_lower'] . '/' . $package['package_id_lower'] . '.' . $version['version_normalized_lower'] . '.nupkg') ?>">
        Download the .nupkg
    </a>
</p>

<div class="card">
    <h2 style="margin-top:0;">Versions</h2>
    <ul>
        <?php foreach ($versions as $row): ?>
            <li>
                <a href="<?= site_url('browse/' . $feed['slug'] . '/' . $package['package_id_lower'] . '/' . $row['version_normalized_lower']) ?>">
                    <?= esc($row['version_normalized']) ?>
                </a>
                <?= $row['id'] === $version['id'] ? ' (current)' : '' ?>
            </li>
        <?php endforeach ?>
    </ul>
</div>

<?php if ($dependencies !== []): ?>
    <div class="card" style="margin-top:1rem;">
        <h2 style="margin-top:0;">Dependencies</h2>
        <table>
            <thead><tr><th>Framework</th><th>Package</th><th>Range</th></tr></thead>
            <tbody>
            <?php foreach ($dependencies as $dep): ?>
                <tr>
                    <td><?= esc($dep['target_framework'] ?? 'any') ?></td>
                    <td><?= esc($dep['dependency_id']) ?></td>
                    <td><code><?= esc($dep['version_range'] ?? '(, )') ?></code></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?php if ($readmeHtml !== null): ?>
    <div class="card" style="margin-top:1rem;">
        <?= $readmeHtml ?>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
