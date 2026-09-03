# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever runs this server: what changed, and what you
have to do when upgrading. When cutting a release, copy the section for that
version into the update panel — `php spark update:manifest` embeds it in the
release, and connected instances see it on `/admin/updates`.

## [1.12.2] - 2026-09-03

### Changed

- **A real favicon**, replacing CodeIgniter's stock default — a bold "P"
  monogram, white on the site's own amber accent, built as a genuine
  multi-resolution `.ico` (16×16 + 32×32) with no image library involved
  (none is available in this environment). Also given an explicit
  `<link rel="icon">` in the layout rather than relying solely on the
  `/favicon.ico` browser convention.

### Upgrading

Nothing to migrate.

## [1.12.1] - 2026-09-03

### Fixed

- The per-version Delist/Delete controls on a package's admin page stacked
  on top of each other — the Delist/Relist `<form>` had no display class,
  so it fell back to block-level and pushed the delete form onto its own
  line. The confirm field's placeholder also gave no hint what to type;
  it now shows the package identifier itself, same as the danger zone's
  own confirm field already did.

### Upgrading

Nothing to migrate.

## [1.12.0] - 2026-09-03

### Added

- **Permanently deleting a package or version from the admin console**, at
  a package's own page — the UI counterpart to `php spark pepite:purge`.
  Requires typing the package identifier back to confirm, same rigor as
  the CLI's own prompt (checked server-side, not just a disabled button).
  The actual mutation moved into a new, shared `App\Libraries\PackagePurger`
  (registered as `service('packagePurger')`) so the CLI and the console
  action can't drift apart.
- **A real `superadmin` tier**, for the first time. Every other admin
  action still only needs `admin`; the two new delete actions need
  `superadmin` too, checked in the controller rather than as a second
  route filter — see the code comment on
  `Admin\Packages::requireSuperadmin()` for why stacking
  `group:admin`/`group:superadmin` as separate route filters would
  silently require *both* memberships rather than expressing "admin, and
  superadmin for this one." No console UI to promote someone — bootstrap
  from the shell (`php spark shield:user addgroup <email> admin` then
  `... addgroup <email> superadmin`, both required); see the README's new
  "Permanently deleting a package" section.
- **Self-service delist/relist**, at `/account/packages/{id}` — an owner
  can now hide or restore one of their own versions from the console, not
  just via `dotnet nuget delete` with a self-service API key (which
  already worked — `PackagePublish::unlist()`/`relist()` already checked
  ownership — there was simply no web UI for it). Mirrors
  `Admin\Packages`' own delist/relist almost exactly, scoped by ownership
  (`PackageOwnerModel::owns()`) instead of the admin group; a non-owner
  gets a 404, same as an unknown package. Deleting stays out of scope here
  — that's the superadmin-only console action above, not extended to
  self-service.
- "My packages" (`/account`) now links each row to this new management
  page, plus a small external-link icon to the public page when the feed
  is public.

### Upgrading

Nothing to migrate. No account is a superadmin by default — the delete UI
stays invisible and its routes refuse everyone, including existing admins,
until someone is deliberately promoted via the two-step CLI bootstrap
above.

## [1.11.1] - 2026-09-02

### Fixed

- **A private feed's read access was scope-only, not feed-scoped.**
  `App\Filters\FeedRead` authorized a Basic-auth read on a *private* feed
  purely on the presented key carrying the `packages.read` scope string —
  it never consulted `feed_api_key_rules`, so a key restricted to feed A
  (via the admin console or `pepite:key --feed`) could still read every
  *other* private feed on the instance too, since nothing about that scope
  said which feed it was meant for. Fixed the same way
  `PublishAuthorizer::authorizeKeyReach` already scopes pushes: a key with
  no row in `feed_api_key_rules` stays fully unrestricted (matching a plain
  nuget.org key), a key with one is now actually confined to the feed(s) it
  names.
- Documented self-service (README, README.fr): what it is, why a
  self-service key never carries `packages.read`, and how to turn on
  e-mail verification once a real mail transport is configured — off by
  default on purpose, since Pépite ships with none and forcing it on would
  silently strand registration on any deployment without one (Docker, most
  local setups, some shared hosts).

### Upgrading

If a key was deliberately restricted to one feed but you were relying on
it also reading a *different* private feed, that access is now closed —
re-issue the key without a restriction, or add a second
`feed_api_key_rules` row, if that access was actually wanted. Nothing else
to migrate; registration/login rate-limiting (Shield's `AuthRates`) and the
self-service key scopes were already in place before this release, only
documented now.

## [1.11.0] - 2026-09-01

### Added

- **Self-service API keys**, at `/account/keys` — any logged-in user can now
  issue their own key to push packages, instead of an admin creating one for
  them by e-mail. This is what makes registration (already open —
  `Config\Auth::$allowRegistration`) actually usable for third parties: until
  now a self-registered account could log in and do nothing package-related.
  A key is scoped to exactly one feed, chosen from those that are both
  public and accept new packages — a feed that doesn't already has no way
  for anyone to become an owner on it, self-service or otherwise, so it
  isn't offered.
- **"My packages"**, at `/account` — every package the current user owns,
  across every feed, reusing the existing first-push-claims-the-identifier
  rule and ownership checks (`PackageOwnerModel`, `PublishAuthorizer`) —
  neither changed. A package owned in a private feed shows without a link:
  the public package page 404s a private feed regardless of ownership, and
  a dead link is worse than none.
- The nav's "My account" link is now visible to any logged-in user; the
  admin-only links (feeds, keys, admins, updates) are now actually gated on
  the admin group rather than just "logged in", which stopped being the same
  thing the moment a self-registered account could exist.

### Upgrading

Nothing to migrate.

A deliberate scope decision worth knowing about: a self-service key never
carries the `packages.read` scope, only `packages.push` and
`packages.unlist`. `App\Filters\FeedRead` (which gates a *private* feed)
authorizes purely on that scope string, with no awareness of which feed a
key is meant to be restricted to — so a key that had it could read every
private feed on the instance, not just the one it was issued for. An
admin-issued key already carries an implicit vetting of the recipient that
self-service cannot offer, so the scope that would matter here is simply
never issued. Nothing needs it: pushing never sends it, and a public feed
needs no authentication to read at all. If `FeedRead` is ever changed to
consult `feed_api_key_rules` per feed, this restriction can be revisited.

## [1.10.0] - 2026-09-01

### Added

- **`php spark pepite:purge <feed-slug> <package-id> [version]`** —
  permanently deletes a package, or one version of it: database rows and
  the stored `.nupkg`/`.nuspec`/icon/readme alike. Nothing else in Pépite
  does this on purpose — a published version is meant to be immutable, and
  unlisting only hides a version from discovery, the flat container still
  serves it by design. Neither helps when a published file has to stop
  existing outright, e.g. because it turns out to contain something that
  should never have been public. Prints exactly what it is about to
  delete and requires typing the package id back to confirm (`--yes` for
  scripted use); dependencies declared against a deleted version are
  removed with it.

### Upgrading

Nothing to migrate. This is a deliberately destructive, operator-run
command — nothing in the app calls it, and it does not appear in the
admin console.

## [1.9.1] - 2026-08-28

### Fixed

- **Illegible text in dark mode** on any `bg-body-tertiary` block — the
  Trusted Publishing setup snippet on a feed's Publishers page was the one
  that got reported, light-grey body text on a background stuck at
  Bootstrap's stock near-white. Root cause: `layout/main.php` overrides
  `--bs-tertiary-bg`, but Bootstrap's opacity-aware utilities
  (`.bg-body-tertiary` among them) read the separate `--bs-tertiary-bg-rgb`
  companion, which was never overridden and so stayed on Bootstrap's light
  default in both themes. Added the missing `-rgb` companions for every hex
  token this app overrides (`--bs-body-bg`, `--bs-body-color`,
  `--bs-emphasis-color`, `--bs-secondary-color`, `--bs-tertiary-bg`) in
  both the light and dark blocks.
- **The Trusted Publishing setup snippet** (GitHub Actions and GitLab
  CI/CD, on a feed's Publishers page and in `docs/trusted-publishing.md`)
  now separates the mint request's HTTP status from its body instead of
  piping straight into `jq`, so a refusal (401/403) surfaces Pépite's own
  error message instead of failing silently and turning into a confusing
  `dotnet nuget push` auth error two steps later. Also checks for an empty
  OIDC token up front, with a message pointing straight at the usual cause
  (`id-token: write` missing from the job's `permissions:`).

### Upgrading

Nothing to migrate. If a feed's Publishers page was screenshotted or its
workflow snippet already copied into a repo before this release, re-copy it
to pick up the more robust error handling — the previous version still
works, it just fails less legibly.

## [1.9.0] - 2026-08-27

### Added

- **The package page shows what was already being stored and never
  displayed** — authors, owners, license (linked when the nuspec gives a
  URL), project and repository links, copyright, package size, the SHA-512
  hash, tags as clickable badges that feed straight into the existing
  search, and the icon, when the package ships one. None of this needed a
  migration: `package_versions` already carried every column, parsed from
  the nuspec at push time and simply never read back.
- **An install snippet**, `.NET CLI` and `PackageReference` tabs, each with
  a copy button — the syntax was previously left for whoever's installing to
  remember or look up elsewhere.
- **Dependencies are grouped by target framework** instead of one flat
  table, matching nuget.org and BaGet.
- **Versions list downloads and publish date per version** — both already
  tracked (`package_versions.downloads`/`published_at`), only ever surfaced
  on the admin side before this.
- **"Used by"** — other packages in the same feed that depend on this one.
  New query (`PackageDependencyModel::usedBy()`), no new table.
- **The feed listing** shows an icon (or a generic placeholder), a truncated
  description and up to five tags per package, and can be sorted by name as
  well as by downloads (`?sort=name`). `PackageModel::search()` pulls the
  latest listed version's icon/description/tags via a correlated subquery
  rather than a second query per row.
- **An Atom feed of recently published versions**, per feed —
  `browse/{slug}/recent.atom`, linked from the feed page via the usual RSS
  icon. The last 30 listed versions across every package in that feed,
  newest first, for whoever would rather watch a feed reader than come back
  and check. A private feed 404s here the same as it does everywhere else.
- **Global search**, from a search box on the home page and a new "Search"
  nav link — one query across every *public* feed at once, instead of
  having to already be inside the right one. Results name which feed each
  hit came from; `PackageModel::search()`'s feed filter is now optional
  rather than required, so the existing per-feed search and the new global
  one share the same query and the same result partial
  (`web/packages/_list.php`).
- **A prerelease badge** next to any version that is one — the package
  page's header, its versions list, and the feed listing — reading the
  `is_prerelease` column that publishing already computes and stores.

### Upgrading

Nothing to migrate — every field this release surfaces was already stored,
and the new features (Atom feed, global search, prerelease badge) add no
tables or columns either.

- **The whole UI now runs on Bootstrap 5.3**, vendored locally rather than
  hand-rolled CSS — admin console, public browsing, the installer, and
  Shield's login/register pages, which previously pulled Bootstrap from a
  CDN into a layout of their own and looked like a different application
  entirely. One root cause behind two problems: the update panel shipped by
  `forgelab-me/ci4-updater` is itself written in Bootstrap classes, so
  without Bootstrap loaded most of it rendered unstyled, and Shield's
  bundled login page extended its own package layout instead of Pépite's.
  Both are now the same design system — `app/Config/Auth.php` points
  Shield's `layout` view at `layout/main`, and the update panel is
  published (`updater:setup --views`) and re-themed to use Pépite's accent
  instead of appearing broken.
- **Bootstrap and Bootstrap Icons are committed under `public/vendor/`**,
  not loaded from a CDN — the admin console and public pages still render
  with no third-party network request, including on a host with no outbound
  internet access at all. See [public/vendor/NOTICE.md](src/public/vendor/NOTICE.md)
  for versions and how to update them.
- Pépite's own palette (the warm accent, the light/dark pair) now bridges
  onto Bootstrap's CSS variable API instead of replacing Bootstrap's blue —
  dark mode still follows the OS via a media query, not a toggle or a
  `data-bs-theme` attribute that would need JavaScript on load.

### Upgrading

Nothing to migrate. If `app/Views/admin/updates.php` was already published
and hand-modified, `php spark updater:setup --views -f` was run to update it
here and will overwrite local customisations — port them across by hand if
that applies to your install.

- **The maintenance window is now automatic, not a manual toggle.** Pépite's
  own `App\Filters\Maintenance` and `pepite:maintenance on|off` predated
  `forgelab-me/ci4-updater` having anywhere to hook into — its own docblock
  said so outright. Since ci4-updater 2.14.0 it does: the package now holds
  a maintenance window open for exactly as long as an apply is writing
  files, panel or the new `updater:apply`/`updater:check` CLI alike
  (2.12.0), migrations included, and closes it on its own with a TTL if the
  process dies mid-write. Two separate, unlinked maintenance flags was a
  real footgun — an update applied via the CLI path was invisible to
  Pépite's own flag entirely. `Forgelabme\Ci4Updater\Filters\Maintenance` is
  now wired globally (`Config\Filters`), exempting `admin/updates/*` so a
  stuck update can still be reached, and a new
  [maintenance.php](src/app/Views/maintenance.php) view keeps the same JSON
  `503` a NuGet client got before for anything under `feeds/*`, and a plain
  page for everything else — previously only the NuGet protocol routes were
  covered at all; the admin console and public browsing pages had no
  protection from a mid-swap read.
- **`composer.json` and `composer.lock` now travel with a release that ships
  `vendor/`.** `publish.yml` builds `vendor/` from the lock file whenever it
  changed, but never shipped the two files describing what's in it — exactly
  the gap `ci4-updater` 2.17.0's root files closes. `Config\Updater` lists
  both in `$allowedFiles`; the workflow passes `--files
  composer.json,composer.lock` to `update:manifest` whenever `vendor` is
  covered.

### Upgrading

Nothing to migrate. If you run the update panel or `updater:apply` from a
shell, nothing changes about how you trigger it — only what happens while it
writes.

## [1.6.0] - 2026-08-26

### Added

- **Trusted Publishing now supports GitLab CI/CD**, alongside GitHub Actions
  — upgraded to `forgelab-me/ci4-trusted-publishing` 1.1.0. A feed's
  **Publishers** page has a provider picker, accepts GitLab's nested
  `group/subgroup/project` repository shape, and shows the matching
  `id_tokens:` YAML to paste. Self-hosted GitLab is configured via
  `Config\TrustedPublishing::$gitlabInstanceUrl` (or `.env`'s
  `trustedpublishing.gitlabInstanceUrl`) — gitlab.com is the default and
  needs nothing set. `POST .../publish/token` no longer assumes GitHub: it
  tries every enabled provider against its own signing keys and accepts
  whichever one the token was actually issued by.
- **Trusted publishers can pin the workflow**, not just the repository and
  environment — a new `workflow` column, populated from GitHub's
  `job_workflow_ref` or GitLab's `ci_config_ref_uri` with the triggering ref
  stripped off. Left blank it behaves exactly as before (any workflow in the
  repository satisfies the row); set it, and a run of any other pipeline
  file in the same repository is refused even if the environment matches. A
  refused mint's `403` names the exact workflow value the token carried, so
  there's nothing to guess when filling the field in.

### Upgrading

Run migrations — one new nullable column, `trusted_publishers.workflow`.
Existing trusted publisher rows are unaffected: an empty workflow matches
any pipeline file, same as before this release.

## [1.5.0] - 2026-08-25

### Added

- **Admin accounts, managed from the console** (`/admin/users`) — create a
  second admin, see who has access, remove it again. Previously the only way
  to add one was `php spark shield:user` from a terminal, which directly
  contradicted the project's own pitch: a shared host with no SSH. A solo
  operator will never notice this was missing; a small team adding a second
  person was stuck without shell access. Removing access demotes an account
  rather than deleting it — a Shield user backs package ownership elsewhere,
  and demoting is the reversible half of that, the same instinct as
  delisting a package instead of removing it. You can't remove your own
  access, and the last remaining admin can't be removed.
- **Rate limiting** on the endpoints nothing else throttled: pushing a
  package, unlisting/relisting, and the Trusted Publishing token exchange
  are now capped per IP (`App\Filters\RateLimit`), and `/login` and
  `/register` now carry Shield's own `AuthRates` filter (10 attempts/minute
  per IP) — it shipped with Shield but was never wired into a route. Both
  gaps meant nothing stood between an attacker and repeatedly trying API
  keys, replaying malformed OIDC tokens, or brute-forcing the login form.

### Upgrading

Nothing to migrate — both additions work with the existing schema.

## [1.4.2] - 2026-08-21

### Changed

- The package README renderer now runs on
  [`league/commonmark`](https://commonmark.thephpleague.com/) (with its GFM
  extension) instead of the hand-rolled regex parser from 1.4.1. That parser
  only ever understood headings, paragraphs and inline formatting — lists,
  blockquotes and tables had no block-level handling at all, so their lines
  fell through to the paragraph case and were squashed onto one run-on line.
  Extending it to cover what a real README needs (lists, tables, blockquotes,
  images, reference links, autolinks…) amounted to rebuilding GFM by hand,
  worse and less tested than the library that already exists for it.

### Security

- league/commonmark's own defaults don't carry over the scheme check 1.4.1
  added: raw HTML passes through untouched, and a `javascript:` link or
  image is allowed. `App\Libraries\Markdown` now pins `html_input: escape`
  and `allow_unsafe_links: false` explicitly, closing the same class of
  stored XSS on the new renderer.

## [1.4.1] - 2026-08-20

### Fixed

- A package README rendered on its public page could carry a Markdown link
  with a `javascript:` (or other non-http) URL. `esc()` encoded the
  characters but never checked the scheme, so the link still ran on click —
  a stored XSS reachable by anyone able to push to a feed. Links now render
  only for `http(s)://` and `mailto:`; anything else degrades to plain text.

## [1.4.0] - 2026-08-18

### Changed

- The admin console and public browsing pages are now entirely in English
  — feeds, API keys, packages, Trusted Publishing, and the web installer.
  Previously French. This project's documentation keeps its French edition
  ([README.fr.md](README.fr.md)); the running application does not.

## [1.3.1] - 2026-08-18

### Fixed

- `Config\Updater::$publicKeys` pointed at `config/keys/release-signing.pub`
  — the wrong case for `app/Config/Keys/`. Harmless on a case-insensitive
  filesystem, but on real hosting the public key was never found, and every
  signed release was refused.

## [1.3.0] - 2026-08-18

### Added

- **Trusted Publishing** — a GitHub Actions workflow can push without ever
  holding a long-lived API key, exchanging its own OIDC identity for a
  scoped, 15-minute one at push time instead. Set up per feed from its
  **Publishers** page, which shows the exact workflow YAML to paste. Built on
  [`forgelab-me/ci4-trusted-publishing`](https://github.com/forgelab-me/ci4-trusted-publishing).
  See [docs/trusted-publishing.md](docs/trusted-publishing.md).
- A footer on every page: copyright and a link back to the project.

### Fixed

- The update panel (`/admin/updates`) reported every outcome — an update
  applied, a failed download, a rollback, a refused signature — as
  flashdata the shared layout never rendered. Every one of those outcomes
  was previously invisible: the page reloaded and nothing said why.
- An upload that failed partway through (oversized body, truncated
  multipart) could leave its partially written temporary file behind on
  disk instead of being cleaned up.

### Upgrading

Run migrations (`php spark migrate --all`, or automatically via the Docker
entrypoint and the update panel) — one new table, `trusted_publishers`.
Nothing else changes: a feed with no trusted publisher configured behaves
exactly as before.

## [1.2.0] - 2026-08-16

Initial public release.

### Added

- **NuGet V3 protocol** — service index, flat container, registration (V2 and
  SemVer 2), search, autocomplete. `dotnet restore`, `dotnet nuget push`,
  Visual Studio and Rider connect to it with no special configuration.
- **Multiple feeds**, public or private, each with its own accepted package
  identifiers (`packageType`).
- **Scoped API keys** — per feed, per identifier pattern, with or without the
  right to create new identifiers. Ownership on first push.
- **Push, unlist, relist** via API key. Delisting only — a client already
  depending on a delisted version keeps restoring it normally.
- **Admin console** — feeds and API keys (create, edit, delete/revoke),
  browsing and moderating packages, download counts per version and per
  package.
- **Web installer** — checks PHP requirements, takes the database connection,
  migrates, creates the first administrator, locks itself after use.
- **Self-update** via [`ci4-updater`](https://github.com/forgelab-me/ci4-updater)
  — check, download, diff, apply, with automatic backup and migrations.
- **Docker image** — server plus MariaDB via `docker compose up -d`, with an
  idempotent entrypoint (migrations, admin account) on every start. See
  [docs/docker.md](docs/docker.md).
- Deployment guidance for shared hosting behind a CDN/WAF (Cloudflare) or a
  host-level WAF (o2switch/ModSecurity): both have blocked NuGet's `PUT`
  push in practice, and both need an explicit allow rule on `/feeds/*/api/v2/`.

### Upgrading

Nothing to migrate from — this is the first tracked release.
