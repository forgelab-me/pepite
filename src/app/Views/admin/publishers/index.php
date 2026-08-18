<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Publieurs de confiance — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Publieurs de confiance — <?= esc($feed['name']) ?></h1>
<p class="lead">
    Un dépôt GitHub listé ici peut échanger son identité OIDC contre une clé API à durée de vie
    courte au moment du push, plutôt que de porter un secret longue durée dans ses paramètres.
    Aucun secret n'est stocké ici.
</p>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if ($publishers === []): ?>
    <p class="muted">Aucun publieur de confiance sur ce feed.</p>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
            <tr>
                <th>Dépôt</th><th>Id du compte</th><th>Environnement</th><th>Motif</th>
                <th>Création</th><th>Dernière utilisation</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($publishers as $publisher): ?>
                <tr>
                    <td><code><?= esc($publisher['repository']) ?></code></td>
                    <td><?= esc($publisher['repository_owner_id']) ?></td>
                    <td><?= esc($publisher['environment'] ?? '—') ?></td>
                    <td><?= esc($publisher['id_pattern'] ?? '(tous)') ?></td>
                    <td><?= $publisher['can_create_package'] ? 'oui' : 'non' ?></td>
                    <td><?= esc($publisher['last_used_at'] ?? 'jamais') ?></td>
                    <td class="actions">
                        <form method="post"
                              action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers/' . $publisher['id'] . '/delete') ?>"
                              onsubmit="return confirm('Retirer la confiance envers « <?= esc($publisher['repository'], 'js') ?> » ?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="small danger">Retirer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<div class="card">
    <h2>Ajouter un publieur de confiance</h2>

    <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers') ?>">
        <?= csrf_field() ?>

        <label>Dépôt (compte/repo)
            <input type="text" name="repository" placeholder="forgelab-me/pepite" required></label>

        <label>Id numérique du compte GitHub
            <input type="text" name="repository_owner_id" placeholder="10387667" required></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Pas le nom du compte — un nom peut être libéré et repris par quelqu'un d'autre, pas
            l'id. À trouver sur <code>api.github.com/users/&lt;compte&gt;</code>.
        </p>

        <label>Environnement GitHub Actions (optionnel)
            <input type="text" name="environment" placeholder="release"></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Vide = n'importe quel job du dépôt. Rempli, il devient obligatoire — combiné à un
            <em>environment</em> GitHub protégé, c'est ce qui place une validation humaine entre
            un push sur le dépôt et une publication sur ce feed.
        </p>

        <label>Motif d'identifiant (glob, optionnel)
            <input type="text" name="id_pattern" placeholder="Contoso.*"></label>

        <label class="checkbox">
            <input type="checkbox" name="can_create_package" value="1">
            Peut créer de nouveaux identifiants (pas seulement pousser des versions existantes)
        </label>

        <p style="margin-top:1.25rem;"><button type="submit" class="primary">Faire confiance</button></p>
    </form>
</div>

<div class="card">
    <h2>Configuration côté GitHub Actions</h2>
    <p>
        Audience OIDC à demander : <code><?= esc($audience) ?></code>
    </p>
    <pre>permissions:
  id-token: write
  contents: read

jobs:
  publish:
    runs-on: ubuntu-latest
    environment: release # doit correspondre à l'environnement configuré ci-dessus
    steps:
      - uses: actions/checkout@v5

      - name: Échanger l'identité GitHub contre une clé de publication
        id: auth
        run: |
          OIDC=$(curl -sS -H "Authorization: bearer $ACTIONS_ID_TOKEN_REQUEST_TOKEN" \
            "$ACTIONS_ID_TOKEN_REQUEST_URL&amp;audience=<?= esc($audience) ?>" | jq -r .value)
          KEY=$(curl -sS --fail-with-body -X POST \
            -H "Authorization: Bearer $OIDC" \
            <?= esc($audience) ?>/feeds/<?= esc($feed['slug']) ?>/api/v2/publish/token | jq -r .token)
          echo "::add-mask::$KEY"
          echo "key=$KEY" &gt;&gt; "$GITHUB_OUTPUT"

      - name: dotnet nuget push
        run: |
          dotnet nuget push package.nupkg \
            -s <?= esc($audience) ?>/feeds/<?= esc($feed['slug']) ?>/v3/index.json \
            -k ${{ steps.auth.outputs.key }}</pre>
</div>

<?= $this->endSection() ?>
