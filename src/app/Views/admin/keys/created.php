<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Key created — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<h1 class="h3">Key issued</h1>
<p class="text-body-secondary">It will never be shown again — only its hash is kept.</p>

<div class="card">
    <div class="card-body">
        <code class="fs-5 text-break"><?= esc($token) ?></code>
    </div>
</div>

<a class="d-inline-block mt-3 text-decoration-none" href="<?= site_url('admin/keys') ?>">&larr; API keys</a>

<?= $this->endSection() ?>
