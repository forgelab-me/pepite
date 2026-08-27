<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= $query === '' ? 'Search' : esc($query) . ' — Search' ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('/') ?>">&larr; Feeds</a>
<h1 class="h3">Search</h1>
<p class="text-body-secondary">Every public feed at once. A private feed is reached from its own page.</p>

<form method="get" action="<?= site_url('search') ?>" class="d-flex flex-wrap gap-2 mb-3">
    <div class="input-group" style="max-width: 24rem;">
        <input type="search" class="form-control" name="q" value="<?= esc($query) ?>" placeholder="Search for a package" autofocus>
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
    <select class="form-select" name="sort" style="max-width: 12rem;" onchange="this.form.submit()">
        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most downloaded</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name (A-Z)</option>
    </select>
</form>

<?= view('web/packages/_list', ['packages' => $packages, 'showFeedBadge' => true]) ?>

<?= $this->endSection() ?>
