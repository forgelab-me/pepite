<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Trusted publishers — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="back-link" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1>Trusted publishers — <?= esc($feed['name']) ?></h1>
<p class="lead">
    A repository listed here can exchange its OIDC identity for a short-lived API key at push
    time, instead of holding a long-lived secret in its settings. No secret is stored here.
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
                <th>Provider</th><th>Repository</th><th>Account id</th><th>Environment</th>
                <th>Workflow</th><th>Pattern</th><th>Can create</th><th>Last used</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($publishers as $publisher): ?>
                <tr>
                    <td><span class="badge accent"><?= esc($publisher['provider'] ?? 'github') ?></span></td>
                    <td><code><?= esc($publisher['repository']) ?></code></td>
                    <td><?= esc($publisher['repository_owner_id']) ?></td>
                    <td><?= esc($publisher['environment'] ?? '—') ?></td>
                    <td><?= isset($publisher['workflow']) && $publisher['workflow'] !== null ? '<code>' . esc($publisher['workflow']) . '</code>' : '—' ?></td>
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

        <label>Provider
            <select name="provider">
                <?php foreach ($providers as $provider): ?>
                    <option value="<?= esc($provider) ?>"><?= esc(ucfirst($provider)) ?></option>
                <?php endforeach ?>
            </select>
        </label>

        <label>Repository (account/repo — GitLab: group/subgroup/project is fine too)
            <input type="text" name="repository" placeholder="forgelab-me/pepite" required></label>

        <label>Numeric account / namespace id
            <input type="text" name="repository_owner_id" placeholder="10387667" required></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Not the account name — a name can be released and claimed by someone else, the id
            can't. GitHub: <code>api.github.com/users/&lt;account&gt;</code>. GitLab: the
            namespace's <code>id</code> field at <code>gitlab.example.com/api/v4/namespaces/&lt;path&gt;</code>.
        </p>

        <label>CI environment (optional)
            <input type="text" name="environment" placeholder="release"></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Empty = any job in the repository. Set, it becomes required — paired with a protected
            environment, this is what puts a human approval between a push to the repository and
            a publish to this feed.
        </p>

        <label>Workflow / pipeline file (optional)
            <input type="text" name="workflow" placeholder=".github/workflows/release.yml"></label>
        <p class="muted" style="margin-top:-.6rem;font-size:.82rem;">
            Empty = any workflow in the repository. Set, only a run of that exact file may mint —
            pinned one level narrower than the environment. GitHub:
            <code>owner/repo/.github/workflows/release.yml</code>. GitLab:
            <code>group/project//.gitlab-ci.yml</code> (the double slash is real — GitLab's own
            claim carries it). The "no trusted publisher matches" error on a refused mint names
            the exact value the token carried, so paste that back here rather than guessing.
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

<?php if (in_array('github', $providers, true)): ?>
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
<?php endif ?>

<?php if (in_array('gitlab', $providers, true)): ?>
<div class="card">
    <h2>GitLab CI/CD configuration</h2>
    <p>
        OIDC audience to request: <code><?= esc($audience) ?></code>. GitLab has no
        <code>::add-mask::</code> equivalent for a value only known at runtime — treat the job log
        as sensitive, or route the push through a masked CI/CD variable if your GitLab plan
        supports it.
    </p>
    <pre>publish:
  id_tokens:
    OIDC_TOKEN:
      aud: <?= esc($audience) ?>
  script:
    - >
      KEY=$(curl -sS --fail-with-body -X POST
      -H "Authorization: Bearer $OIDC_TOKEN"
      <?= esc($audience) ?>/feeds/<?= esc($feed['slug']) ?>/api/v2/publish/token | jq -r .token)
    - dotnet nuget push package.nupkg -s <?= esc($audience) ?>/feeds/<?= esc($feed['slug']) ?>/v3/index.json -k "$KEY"</pre>
</div>
<?php endif ?>

<?= $this->endSection() ?>
