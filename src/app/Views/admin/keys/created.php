<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Key created — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<h1>Key issued</h1>
<p>It will never be shown again — only its hash is kept.</p>

<div class="card">
    <code style="font-size:1.1rem; word-break:break-all;"><?= esc($token) ?></code>
</div>

<p style="margin-top:1rem;"><a href="<?= site_url('admin/keys') ?>">&larr; API keys</a></p>

<?= $this->endSection() ?>
