# Trusted Publishing

A GitHub Actions workflow can push packages without ever holding a Pépite API
key. Instead of a secret sitting in the repo's settings, the workflow uses
its own [OIDC identity token](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/about-security-hardening-with-openid-connect)
— issued fresh by GitHub on every run, signed by GitHub, and never seen by
anyone — to ask Pépite for a short-lived, narrowly scoped key at push time.
Modeled on the same mechanism [npm](https://docs.npmjs.com/trusted-publishers),
[PyPI](https://docs.pypi.org/trusted-publishers/) and
[nuget.org](https://learn.microsoft.com/en-us/nuget/nuget-org/trusted-publishing)
offer.

No secret is stored on either side. Pépite stores a *policy* — which
repository, which GitHub account, optionally which
[environment](https://docs.github.com/en/actions/deployment/targeting-different-environments/using-environments-for-deployment)
— never a credential.

Verification, identity normalisation and policy matching are handled by
[`forgelab-me/ci4-trusted-publishing`](https://github.com/forgelab-me/ci4-trusted-publishing),
extracted from this exact code so it isn't duplicated across every app that
wants the same mechanism. Pépite owns everything specific to itself: the
`trusted_publishers` table, the feed it belongs to, and how the minted
credential is authorized to push (a `feed_api_key_rules` row, same as any
other API key).

## Setting it up

1. In the admin console, open a feed's **Publieurs** page
   (`/admin/feeds/{id}/publishers`).
2. Add the repository (`owner/name`) and the GitHub account's **numeric** id
   — not its name. Names can be freed and reclaimed by someone else; the id
   never changes. Find it at `https://api.github.com/users/<account>`.
3. Optionally require a GitHub Actions **environment**. Left blank, any job
   in the repository can mint. Set it, and only a job that ran in that named
   environment can — which matters once that environment is a GitHub
   *protected* environment requiring manual approval: that approval now sits
   between a push to the repository and a publish to the feed.
4. Optionally restrict the identifier pattern and whether new package
   identifiers may be created, exactly like a manually issued API key (see
   the main README's Security section).

The page shows the exact workflow YAML to paste, with this server's URL and
the feed's slug already filled in.

## What happens at push time

```yaml
permissions:
  id-token: write   # required to mint the identity token
  contents: read

jobs:
  publish:
    runs-on: ubuntu-latest
    environment: release   # only if the trusted publisher requires one
    steps:
      - uses: actions/checkout@v5

      - name: Exchange the GitHub identity for a publish key
        id: auth
        run: |
          OIDC=$(curl -sS -H "Authorization: bearer $ACTIONS_ID_TOKEN_REQUEST_TOKEN" \
            "$ACTIONS_ID_TOKEN_REQUEST_URL&audience=https://pepite.example.com" | jq -r .value)
          KEY=$(curl -sS --fail-with-body -X POST \
            -H "Authorization: Bearer $OIDC" \
            https://pepite.example.com/feeds/default/api/v2/publish/token | jq -r .token)
          echo "::add-mask::$KEY"
          echo "key=$KEY" >> "$GITHUB_OUTPUT"

      - name: dotnet nuget push
        run: |
          dotnet nuget push package.nupkg \
            -s https://pepite.example.com/feeds/default/v3/index.json \
            -k ${{ steps.auth.outputs.key }}
```

1. GitHub Actions mints an OIDC token for the audience the workflow asked
   for — this server's own URL. Requesting any other audience is what would
   let a token minted for a different service be replayed here, so the
   audience is checked, not just the signature.
2. `POST /feeds/{slug}/api/v2/publish/token` verifies that token against
   GitHub's own signing keys (fetched from
   `https://token.actions.githubusercontent.com/.well-known/jwks`, cached a
   day at a time), then checks its `repository`, `repository_owner_id` and
   `environment` claims against the feed's trusted publishers.
3. On a match, it mints a real NuGet API key — the exact kind an admin could
   create by hand — scoped to `packages.push` (never `packages.unlist`),
   restricted by whatever identifier pattern and create-permission the
   trusted publisher entry carries, and expiring in 15 minutes. A
   `feed_api_key_rules` row is written for it, same as a manually issued key.
4. `dotnet nuget push` uses that key exactly like any other.

The exchange endpoint and the push endpoint are entirely decoupled: once
minted, the key is indistinguishable from one issued through the admin
console or `pepite:key`. The push endpoint has no idea Trusted Publishing
exists.

## Why the key can never unlist or delete

A minted key's scope is fixed at `packages.push`. Even a trusted publisher
row with every restriction left open cannot produce a key that can unlist,
relist, or manage feeds — a compromised or leaked minted key is bounded to
"push a version that matches the configured pattern," and it stops being
usable at all within 15 minutes regardless.

## Diagnosing a refusal

| Status | Meaning |
|---|---|
| `404` | No feed with that slug. |
| `401` | Missing `Authorization: Bearer`, or the token failed verification (wrong issuer, wrong audience, bad signature, expired). |
| `403` | The token verified, but no trusted publisher on the feed matches its `repository` / `repository_owner_id` / `environment` — or the account that added the trusted publisher no longer exists. |

A `403` names exactly what it compared against (repository, owner id,
environment) rather than leaving that to be guessed — the admin console form
and the error message use the same three fields on purpose.

`id-token: write` missing from the workflow's `permissions:` block is the
most common setup mistake: `$ACTIONS_ID_TOKEN_REQUEST_TOKEN` is then empty,
and the first `curl` fails before Pépite is ever involved.
