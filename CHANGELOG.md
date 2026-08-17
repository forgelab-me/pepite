# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever runs this server: what changed, and what you
have to do when upgrading. When cutting a release, copy the section for that
version into the update panel — `php spark update:manifest` embeds it in the
release, and connected instances see it on `/admin/updates`.

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
