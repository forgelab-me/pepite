<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?><?= esc($package['package_id']) ?> — Admin<?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds/' . $feed['id'] . '/packages') ?>">&larr; Packages — <?= esc($feed['name']) ?></a>
<h1><?= esc($package['package_id']) ?></h1>
<p class="lead">
    Propriétaire(s) :
    <?= $owners === [] ? 'aucun' : esc(implode(', ', $owners)) ?>
</p>

<div class="card">
    <table>
        <thead><tr><th>Version</th><th>Statut</th><th>Téléchargements</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($versions as $version): ?>
            <tr>
                <td><?= esc($version['version_normalized']) ?></td>
                <td>
                    <span class="badge <?= $version['is_listed'] ? 'ok' : 'warn' ?>">
                        <?= $version['is_listed'] ? 'listée' : 'délistée' ?>
                    </span>
                </td>
                <td><?= (int) $version['downloads'] ?></td>
                <td class="actions">
                    <?php $base = 'admin/feeds/' . $feed['id'] . '/packages/' . $package['id'] . '/versions/' . $version['id']; ?>
                    <?php if ($version['is_listed']): ?>
                        <form method="post" action="<?= site_url($base . '/unlist') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="small">Délister</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= site_url($base . '/relist') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="small">Relister</button>
                        </form>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<p class="muted" style="margin-top:1rem;">
    Le délistage masque une version des résultats de recherche sans la supprimer : un client qui
    en dépend déjà continue de la restaurer normalement.
</p>

<?= $this->endSection() ?>
