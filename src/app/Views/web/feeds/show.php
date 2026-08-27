<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($feed['name']) ?> — Pépite<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('/') ?>">&larr; Feeds</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1"><?= esc($feed['name']) ?></h1>
        <?php if (! empty($feed['description'])): ?><p class="text-body-secondary"><?= esc($feed['description']) ?></p><?php endif ?>
    </div>
    <a class="text-body-secondary" href="<?= site_url('browse/' . $feed['slug'] . '/recent.atom') ?>" title="Recent versions, as an Atom feed">
        <i class="bi bi-rss"></i>
    </a>
</div>

<form method="get" class="d-flex flex-wrap gap-2 mb-3">
    <div class="input-group" style="max-width: 24rem;">
        <input type="search" class="form-control" name="q" value="<?= esc($query) ?>" placeholder="Search for a package">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
    <select class="form-select" name="sort" style="max-width: 12rem;" onchange="this.form.submit()">
        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most downloaded</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name (A-Z)</option>
    </select>
</form>

<?= view('web/packages/_list', ['packages' => $packages, 'showFeedBadge' => false]) ?>

<?= $this->endSection() ?>
