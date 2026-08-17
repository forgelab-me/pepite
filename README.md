# Pépite

[🇫🇷 Version française](README.fr.md)

[![Tests](https://github.com/forgelab-me/pepite/actions/workflows/tests.yml/badge.svg)](https://github.com/forgelab-me/pepite/actions/workflows/tests.yml)
[![Docker image](https://github.com/forgelab-me/pepite/actions/workflows/docker.yml/badge.svg)](https://github.com/forgelab-me/pepite/actions/workflows/docker.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A NuGet server (V3 protocol) in PHP / CodeIgniter 4. Existing self-hosted implementations
(BaGet, LiGet, NuGet.Server, ProGet) all require .NET and dedicated hosting — Pépite targets
the opposite case: a few-euros-a-month shared host, no Docker, no daemon, no SSH required.
A Docker image exists too, for anyone who'd rather use that.

- **Full V3 protocol** — service index, flat container, registration (V2 and SemVer 2), search,
  autocomplete. `dotnet restore`, `dotnet nuget push`, Visual Studio and Rider connect to it with
  no special configuration.
- **Multiple feeds**, public or private, each with its own accepted package identifiers
  (`packageType`) — enough to expose an application catalog separate from ordinary .NET
  libraries without running a second server.
- **Scoped API keys** — per feed, per identifier pattern (`Contoso.*`), with or without the
  right to create new identifiers. Ownership on first push.
- **Delisting, never deletion** — a client already depending on a delisted version keeps
  restoring it normally; only its visibility in search changes.
- **Admin console** — feeds, API keys, browsing and moderating packages (delist / relist), all
  in the browser, no command line needed.
- **Web installer** — checks PHP requirements, takes the database connection, migrates, creates
  the first administrator account, locks itself after use.
- **Self-update** ([`ci4-updater`](https://github.com/forgelab-me/ci4-updater)) — the
  `/admin/updates` panel checks, downloads, diffs and applies releases, with automatic backup
  and migrations. The mechanism this project was built around in the first place: a shared host
  with no SSH.

## Docker

```bash
curl -O https://raw.githubusercontent.com/forgelab-me/pepite/main/compose.yaml
curl -o .env https://raw.githubusercontent.com/forgelab-me/pepite/main/.env.example
# edit .env, then:
docker compose up -d
```

Server plus MariaDB, from `ghcr.io/forgelab-me/pepite` (amd64 and arm64). The container waits
for the database, migrates, and creates or re-syncs the admin account on every start — all of
it idempotent.

Self-update is effectively disabled in this mode: updating means pulling a new image
(`docker compose pull && docker compose up -d`), not clicking through the `/admin/updates`
panel, which would write to a filesystem discarded on the next deploy.

Full guide — configuration, backups, reverse proxy: **[docs/docker.md](docs/docker.md)**.

## Install from source

The application lives in `src/`; the repository root holds packaging and documentation. Every
command below runs from `src/`.

### Requirements

- PHP **8.2+** with `intl`, `mbstring`, `zip`, `dom`
- SQLite (development) or MySQL / MariaDB (production)
- HTTPS in production — the .NET SDK refuses HTTP sources

### Development

```bash
git clone https://github.com/forgelab-me/pepite.git
cd pepite/src
composer install
cp env .env          # then set CI_ENVIRONMENT, app.baseURL and the database
php spark migrate --all
php spark db:seed DevAdminSeeder
php spark serve
```

The development administrator is created as `admin@pepite.test` / `pepite-dev-2026`, or the
values of the `PEPITE_DEV_ADMIN_EMAIL` and `PEPITE_DEV_ADMIN_PASSWORD` environment variables.
This account only exists in development (`DevAdminSeeder` refuses to run in production): in
production it's the web installer, or `AdminUserSeeder` for the Docker image, that creates the
first account.

### Checks

```bash
composer test
```

```bash
composer cs
```

`composer cs:fix` applies style fixes. The standard is CodeIgniter 4's own.

Development runs on SQLite and production on MySQL/MariaDB; the two engines disagree on
something that matters (the collation of `version_sort_key`), so CI runs the suite on both —
see [`tools/test-mysql.sh`](tools/test-mysql.sh) to reproduce that locally.

## Landmarks

| Path | Role |
|---|---|
| `src/app/Libraries/Version/` | NuGet versions and ranges, sort key |
| `src/app/Libraries/Package/` | Reading `.nupkg`, parsing `.nuspec` |
| `src/app/Libraries/Http/` | Streaming multipart `PUT` parsing |
| `src/app/Controllers/V3/` | The NuGet V3 protocol |
| `src/app/Controllers/Api/` | Publishing, delisting |
| `src/app/Controllers/Admin/` | Admin console — feeds, keys, packages |
| `src/writable/storage/` | Package blobs, outside the web root |

Classes under `Libraries/Version`, `Libraries/Package` and `Libraries/Http` call none of
`service()`, `config()`, `db_connect()` or `request()`: they're ordinary PHP classes, built by
their constructor and tested without booting the framework.

The protocol's routes live under `/feeds/{slug}/…` and never inherit the session or CSRF
filters: a command-line client has neither a cookie nor a token.

## Deploying on shared hosting (OVH and equivalents)

1. Build a release: `php spark update:manifest` (from `src/`). The resulting ZIP includes
   `vendor/` — there is no Composer on shared hosting.
2. Unzip at the account root, with the **web root pointed at `public/`**.
3. Open `https://yourdomain/install`: it checks PHP extensions, takes the database connection,
   runs migrations, creates the administrator account and writes `.env`. It then locks itself
   (`writable/install.lock`).
4. `public/.user.ini` raises the default upload limits; some hosts take a few minutes to pick
   it up.
5. Later updates: the `/admin/updates` panel. While a release is being applied,
   `php spark pepite:maintenance on` makes NuGet clients get a `503` instead of hitting files
   mid-replacement; `off` once it's done.

`writable/` and `.env` must be writable by PHP. `writable/storage/` (the package blobs) must
stay outside the web root.

**If a CDN/WAF (Cloudflare in particular) sits in front of the instance**: its bot protections
often block `curl`, `dotnet` and `nuget.exe` while a browser passes through — even on a plain
`GET`. Quick check once deployed:

```bash
curl -i https://yourdomain/feeds/default/v3/index.json
```

A generic `403` (not Pépite's own JSON response) while the same URL works in a browser means
the CDN is blocking non-browser clients. On Cloudflare: **Security → WAF → Custom rules**, rule
`URI Path contains /feeds/` → **Skip**: Bot Fight Mode, Super Bot Fight Mode, Security Level,
Browser Integrity Check.

## Security

- The admin console (`/admin/*`) is authenticated via
  [`codeigniter4/shield`](https://github.com/codeigniter4/shield) — only trusted accounts
  should have access, since creating an API key or a feed grants access to publishing.
- An unrestricted API key (no `feed_api_key_rules` row) can publish to any feed that allows new
  identifiers. Restrict a key to a feed and an identifier pattern (`Contoso.*`) as soon as it
  leaves strictly personal use.
- `.env` is excluded from the repository — never commit real secrets there. Only `env` (the
  CI4 template) is tracked.
- The release-signing private key (`php spark updater:keygen`) must **never** leave the machine
  that builds releases; only its public half goes into `Config\Updater::$publicKeys`.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — written for whoever runs this server, including what to do
when upgrading.

## License

MIT — see [LICENSE](LICENSE).

NuGet is a trademark of the .NET Foundation. Pépite is an independent project, not affiliated
with Microsoft or the .NET Foundation.
