# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever runs this server: what changed, and what you
have to do when upgrading. When cutting a release, copy the section for that
version into the update panel — `php spark update:manifest` embeds it in the
release, and connected instances see it on `/admin/updates`.

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
