<?= $this->extend('layout/main') ?>
<?= $this->section('title') ?>Trusted publishers — <?= esc($feed['name']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<a class="d-inline-block mb-3 text-body-secondary text-decoration-none" href="<?= site_url('admin/feeds') ?>">&larr; Feeds</a>
<h1 class="h3">Trusted publishers — <?= esc($feed['name']) ?></h1>
<p class="text-body-secondary">
    A repository listed here can exchange its OIDC identity for a short-lived API key at push
    time, instead of holding a long-lived secret in its settings. No secret is stored here.
</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><p class="mb-0"><?= esc($error) ?></p><?php endforeach ?></div>
<?php endif ?>

<?php if ($publishers === []): ?>
    <p class="text-body-secondary">No trusted publishers on this feed.</p>
<?php else: ?>
    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>Provider</th><th>Repository</th><th>Account id</th><th>Environment</th>
                    <th>Workflow</th><th>Pattern</th><th>Can create</th><th>Last used</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($publishers as $publisher): ?>
                    <tr>
                        <td><span class="badge text-bg-primary"><?= esc($publisher['provider'] ?? 'github') ?></span></td>
                        <td><code><?= esc($publisher['repository']) ?></code></td>
                        <td><?= esc($publisher['repository_owner_id']) ?></td>
                        <td><?= esc($publisher['environment'] ?? '—') ?></td>
                        <td><?= isset($publisher['workflow']) && $publisher['workflow'] !== null ? '<code>' . esc($publisher['workflow']) . '</code>' : '—' ?></td>
                        <td><?= esc($publisher['id_pattern'] ?? '(any)') ?></td>
                        <td><?= $publisher['can_create_package'] ? 'yes' : 'no' ?></td>
                        <td><?= esc($publisher['last_used_at'] ?? 'never') ?></td>
                        <td class="text-end">
                            <form method="post"
                                  action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers/' . $publisher['id'] . '/delete') ?>"
                                  onsubmit="return confirm('Remove trust in &quot;<?= esc($publisher['repository'], 'js') ?>&quot;?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>

<div class="card mb-3">
    <div class="card-header">Add a trusted publisher</div>
    <div class="card-body">
        <form method="post" action="<?= site_url('admin/feeds/' . $feed['id'] . '/publishers') ?>">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label" for="provider">Provider</label>
                <select class="form-select" id="provider" name="provider">
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= esc($provider) ?>"><?= esc(ucfirst($provider)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" for="repository">Repository (account/repo — GitLab: group/subgroup/project is fine too)</label>
                <input class="form-control" type="text" id="repository" name="repository" placeholder="forgelab-me/pepite" required>
            </div>

            <div class="mb-1">
                <label class="form-label" for="repository_owner_id">Numeric account / namespace id</label>
                <input class="form-control" type="text" id="repository_owner_id" name="repository_owner_id" placeholder="10387667" required>
            </div>
            <p class="text-body-secondary small mb-3">
                Not the account name — a name can be released and claimed by someone else, the id
                can't. GitHub: <code>api.github.com/users/&lt;account&gt;</code>. GitLab: the
                namespace's <code>id</code> field at <code>gitlab.example.com/api/v4/namespaces/&lt;path&gt;</code>.
            </p>

            <div class="mb-1">
                <label class="form-label" for="environment">CI environment (optional)</label>
                <input class="form-control" type="text" id="environment" name="environment" placeholder="release">
            </div>
            <p class="text-body-secondary small mb-3">
                Empty = any job in the repository. Set, it becomes required — paired with a protected
                environment, this is what puts a human approval between a push to the repository and
                a publish to this feed.
            </p>

            <div class="mb-1">
                <label class="form-label" for="workflow">Workflow / pipeline file (optional)</label>
                <input class="form-control" type="text" id="workflow" name="workflow" placeholder=".github/workflows/release.yml">
            </div>
            <p class="text-body-secondary small mb-3">
                Empty = any workflow in the repository. Set, only a run of that exact file may mint —
                pinned one level narrower than the environment. GitHub:
                <code>owner/repo/.github/workflows/release.yml</code>. GitLab:
                <code>group/project//.gitlab-ci.yml</code> (the double slash is real — GitLab's own
                claim carries it). The "no trusted publisher matches" error on a refused mint names
                the exact value the token carried, so paste that back here rather than guessing.
            </p>

            <div class="mb-3">
                <label class="form-label" for="id_pattern">Identifier pattern (glob, optional)</label>
                <input class="form-control" type="text" id="id_pattern" name="id_pattern" placeholder="Contoso.*">
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="can_create_package" name="can_create_package" value="1">
                <label class="form-check-label" for="can_create_package">Can create new identifiers (not just push existing versions)</label>
            </div>

            <button type="submit" class="btn btn-primary">Trust it</button>
        </form>
    </div>
</div>

<?php if (in_array('github', $providers, true)): ?>
<div class="card mb-3">
    <div class="card-header">GitHub Actions configuration</div>
    <div class="card-body">
        <p>OIDC audience to request: <code><?= esc($audience) ?></code></p>
        <pre class="bg-body-tertiary p-3 rounded small mb-0">permissions:
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
</div>
<?php endif ?>

<?php if (in_array('gitlab', $providers, true)): ?>
<div class="card">
    <div class="card-header">GitLab CI/CD configuration</div>
    <div class="card-body">
        <p>
            OIDC audience to request: <code><?= esc($audience) ?></code>. GitLab has no
            <code>::add-mask::</code> equivalent for a value only known at runtime — treat the job log
            as sensitive, or route the push through a masked CI/CD variable if your GitLab plan
            supports it.
        </p>
        <pre class="bg-body-tertiary p-3 rounded small mb-0">publish:
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
</div>
<?php endif ?>

<?= $this->endSection() ?>
