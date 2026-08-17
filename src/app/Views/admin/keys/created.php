<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Clé créée — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<h1>Clé émise</h1>
<p>Elle ne sera plus jamais affichée — seul son hachage est conservé.</p>

<div class="card">
    <code style="font-size:1.1rem; word-break:break-all;"><?= esc($token) ?></code>
</div>

<p style="margin-top:1rem;"><a href="<?= site_url('admin/keys') ?>">&larr; Clés API</a></p>

<?= $this->endSection() ?>
