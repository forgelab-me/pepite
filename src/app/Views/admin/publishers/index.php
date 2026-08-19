<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Trusted publishers — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Trusted publishers — <?= esc($feed['name']) ?></h1>
<p class="lead">
    A GitHub repository listed here can exchange its OIDC identity for a short-lived API key at
    push time, instead of holding a long-lived secret in its settings. No secret is stored here.
</p>

<?php if ($errors !== []): ?>
    <div class="errors"><?php foreach ($errors as $error): ?><p><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if ($publishers === []): ?>
    <p class="muted">No trusted publishers on this feed.</p>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
            <tr>
                <th>Repository</th><th>Account id</th><th>Environment</th><th>Pattern</th>
                <th>Can create</th><th>Last used</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($publishers as $publisher): ?>
                <tr>
                    <td><code><?= esc($publisher['repository']) ?></code></td>
                    <td><?= esc($publisher['repository_owner_id']) ?></td>
                    <td><?= esc($publisher['environment'] ?? '—') ?></td>
                    <td><?= esc($publisher['id_pattern'] ?? '(any)') ?></td>
                    <td><?= $publisher['can_create_package'] ? 'yes' : 'no' ?></td>
                    <td><?= esc($publisher['last_used_at'] ?? 'never') ?></td>
                    <td class="actions">
                        <form method="post"
                              action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers/' . $publisher['id'] . '/delete') ?>"
                              onsubmit="return confirm('Remove trust in &quot;<?= esc($publisher['repository'], 'js') ?>&quot;?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="small danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>

<div class="card">
    <h2>Add a trusted publisher</h2>

    <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers') ?>">
        <?= csrf_field() ?>

        <label>Repository (account/repo)
            <input type="text" name="repository" placeholder="forgelab-me/pepite" required></label>

        <label>Numeric GitHub account id
            <input type="text" name="repository_owner_id" placeholder="10387667" required></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Not the account name — a name can be released and claimed by someone else, the id
            can't. Find it at <code>api.github.com/users/&lt;account&gt;</code>.
        </p>

        <label>GitHub Actions environment (optional)
            <input type="text" name="environment" placeholder="release"></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Empty = any job in the repository. Set, it becomes required — paired with a protected
            GitHub <em>environment</em>, this is what puts a human approval between a push to the
            repository and a publish to this feed.
        </p>

        <label>Identifier pattern (glob, optional)
            <input type="text" name="id_pattern" placeholder="Contoso.*"></label>

        <label class="checkbox">
            <input type="checkbox" name="can_create_package" value="1">
            Can create new identifiers (not just push existing versions)
        </label>

        <p style="margin-top:1.25rem;"><button type="submit" class="primary">Trust it</button></p>
    </form>
</div>

<div class="card">
    <h2>GitHub Actions configuration</h2>
    <p>
        OIDC audience to request: <code><?= esc($audience) ?></code>
    </p>
    <pre>permissions:
  id-token: write
  contents: read

jobs:
  publish:
    runs-on: ubuntu-latest
    environment: release # must match the environment configured above
    steps:
      - uses: actions/checkout@v5

      - name: Exchange the GitHub identity for a publish key
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
