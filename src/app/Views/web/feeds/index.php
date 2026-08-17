<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<h1>Feeds</h1>

<?php if ($feeds === []): ?>
    <p>Aucun feed public pour l'instant.</p>
<?php else: ?>
    <div class="card">
        <table>
            <thead><tr><th>Feed</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($feeds as $feed): ?>
                <tr>
                    <td><a href="<?= site_url('browse/' . $feed['slug']) ?>"><?= esc($feed['name']) ?></a></td>
                    <td><?= esc($feed['description'] ?? '') ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<?= $this->endSection() ?>
