<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<h1 class="h3 mb-3">Feeds</h1>

<?php if ($feeds === []): ?>
    <p class="text-body-secondary">No public feed yet.</p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
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
    </div>
<?php endif ?>

<?= $this->endSection() ?>
