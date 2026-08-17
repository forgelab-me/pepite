# Running with Docker

```bash
curl -O https://raw.githubusercontent.com/forgelab-me/pepite/main/compose.yaml
curl -o .env https://raw.githubusercontent.com/forgelab-me/pepite/main/.env.example

# edit .env — at minimum app_baseURL, database_default_password,
# ADMIN_EMAIL and ADMIN_PASSWORD

docker compose up -d
```

The stack is Pépite plus MariaDB. On every start the container waits for the
database, applies pending migrations, and creates or re-syncs the admin
account. All of it is idempotent, so restarting and redeploying are safe.

Images: `ghcr.io/forgelab-me/pepite`, tagged `1.2.3`, `1.2`, `1` and `latest`,
built for `linux/amd64` and `linux/arm64`. Pin at whichever level of churn
you want to follow.

## Configuration

No `.env` is copied into the image — CodeIgniter reads real environment
variables, and that is what Compose passes.

| Variable | Purpose |
|---|---|
| `app_baseURL` | Public URL, trailing slash included. NuGet clients are told where to fetch packages from using this, so it must be how they actually reach the server. The .NET SDK refuses HTTP sources outright. |
| `app_forceGlobalSecureRequests` | Redirect everything to HTTPS. Leave on unless a proxy already does it. |
| `database_default_database` / `_username` / `_password` | Database credentials. Host, driver and port are set by `compose.yaml`. |
| `ADMIN_EMAIL` | First admin account. |
| `ADMIN_PASSWORD` | Its password, hashed by the container at startup. |
| `ADMIN_PASSWORD_HASH` | A bcrypt hash, if you prefer not to store the plaintext. Takes precedence over `ADMIN_PASSWORD`. |
| `RUN_MIGRATIONS` | Set to `false` to skip migrations at startup. |
| `APP_PORT` | Host port. Behind a proxy, `127.0.0.1:8080` keeps it off the public interface. |

### Three traps worth knowing about

These each cost real debugging time on the sibling project this image's
Dockerfile is based on, so they are worth stating plainly.

**Use the underscore spelling.** `app_baseURL`, not `app.baseURL`. The
container's shell is dash, which discards environment variables whose names
aren't valid shell identifiers — dots included. With the dotted form the
settings never reach PHP or Apache, and the app fails as though nothing was
configured.

**Two `.env` files, and that is deliberate.** `/.env` at the repository root
configures Compose and the container; `/src/.env` configures CodeIgniter in a
source checkout. They sit in different directories precisely so neither can
overwrite the other — which is why the application lives under `src/`.

**Beware `$` in a bcrypt hash.** Compose interpolates `$` in env files, so
pasting a hash there silently drops part of it and produces an account that
accepts no password — with no error anywhere. Either use `ADMIN_PASSWORD` and
let the container hash it, or double every `$`:

```bash
php -r "echo str_replace('\$', '\$\$', password_hash('yourpassword', PASSWORD_DEFAULT)) . PHP_EOL;"
```

The entrypoint rejects a hash that doesn't parse rather than seeding an
unusable account.

## Updating

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on the new container. Pin a tag (`:1.2`) if you
would rather decide when a major version lands.

The `/admin/updates` panel (self-update via `ci4-updater`) is present but
**not meaningful inside this image**: applying an update would write into the
container's filesystem, which is discarded the moment the container is
recreated — the panel would report success and then vanish on the next
`docker compose up`. Pulling a new image is the update mechanism here; leave
that panel alone.

## Data and backups

`/var/www/html/writable` holds every package blob this instance has ever
served (`writable/storage/`) — **losing that volume loses every package of
every feed**. Back it up alongside the database.

```bash
# Package blobs
docker run --rm -v pepite_pepite_data:/data -v "$PWD:/backup" \
  alpine tar czf /backup/pepite-data.tar.gz -C /data .

# Database
docker compose exec db \
  mariadb-dump -upepite -p"$database_default_password" pepite > pepite-db.sql
```

## Behind a reverse proxy

Publish on loopback (`APP_PORT=127.0.0.1:8080`), terminate TLS at the proxy,
and forward the usual headers. Keep `app_baseURL` set to the public HTTPS
URL: it is embedded in every URL the V3 service index hands a client
(`flatcontainer`, `registration`, `search`), so getting it wrong sends
`dotnet nuget` to an address it can't reach.

## Building locally

```bash
docker compose up -d --build
```

A `compose.override.yaml` with `build: .` is enough to build from source
instead of pulling; Compose picks it up automatically.

## Health

The image declares a healthcheck, so `docker compose ps` reports `healthy`
once the app answers. Startup logs are prefixed `[entrypoint]`:

```bash
docker compose logs app | grep entrypoint
```

## SQLite instead of MariaDB

The base image ships `sqlite3`/`pdo_sqlite`, so a single-container setup
without the `db` service is possible for small or low-traffic instances —
drop the `db` service and its `depends_on`, and set:

```
database_default_DBDriver=SQLite3
database_default_database=pepite.db
```

`pepite.db` resolves under `writable/`, so it lives in the same volume as the
package blobs and gets backed up by the same `tar` command above. Production
on shared hosting (the deployment this project is built for first) is
documented in the main [README](../README.md#deploying-on-shared-hosting-ovh-and-equivalents)
and runs the same way, MySQL/MariaDB included.
