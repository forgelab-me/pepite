#!/bin/sh
set -e

# Runs before Apache on every start. Everything here is idempotent, so
# restarting a container is never destructive.

log() { echo "[entrypoint] $*"; }

# writable/ is usually a volume, which starts out owned by root.
if [ -d writable ]; then
    chown -R www-data:www-data writable 2>/dev/null || true
fi

# --- Wait for the database ------------------------------------------------
#
# Two things this has to get right:
#
#  * The variables use CodeIgniter's underscore form (database_default_hostname,
#    app_baseURL) rather than the dotted one. /bin/sh is dash here, and dash
#    drops environment variables whose names aren't valid shell identifiers —
#    dots included. With the dotted form, nothing downstream of this script,
#    Apache included, would ever see the database settings.
#  * A listening port is not a ready database: MariaDB accepts connections
#    while it is still creating the user and schema. So this opens a real
#    connection with the real credentials instead of probing the port.
#
# Skipped entirely when no host is configured — SQLite needs no server, and
# the base image ships sqlite3/pdo_sqlite for exactly that case.
log "waiting for the database"
php -r '
    $host = getenv("database_default_hostname") ?: getenv("database.default.hostname") ?: getenv("DB_HOST");
    if (! $host) {
        fwrite(STDERR, "[entrypoint] no database host configured — skipping the wait\n");
        exit(0);
    }

    $port = (int) (getenv("database_default_port") ?: getenv("database.default.port") ?: 3306);
    $user = (string) (getenv("database_default_username") ?: getenv("database.default.username") ?: "");
    $pass = (string) (getenv("database_default_password") ?: getenv("database.default.password") ?: "");
    $name = (string) (getenv("database_default_database") ?: getenv("database.default.database") ?: "");

    mysqli_report(MYSQLI_REPORT_OFF);

    for ($i = 1; $i <= 60; $i++) {
        $link = @new mysqli($host, $user, $pass, $name, $port);
        if ($link->connect_errno === 0) {
            $link->close();
            fwrite(STDERR, "[entrypoint] database ready after {$i}s\n");
            exit(0);
        }
        sleep(1);
    }

    fwrite(STDERR, "[entrypoint] database still unreachable after 60s: " . $link->connect_error . "\n");
    exit(1);
' || {
    log "giving up on the database — not running migrations"
    exec "$@"
}

# --- Schema ---------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-true}" != "false" ]; then
    log "running migrations"
    php spark migrate --all || log "migrations failed — check the log above"
fi

# --- First admin ------------------------------------------------------------
#
# ADMIN_PASSWORD (plain) is offered alongside ADMIN_PASSWORD_HASH because a
# bcrypt hash is full of $, and Compose interpolates $ in env files: pasting a
# hash there silently loses part of it and produces an account nobody can log
# in to. Supplying the password and hashing it here sidesteps that entirely.
# A hash still wins if both are set — escape its $ as $$ in the env file.
if [ -z "${ADMIN_PASSWORD_HASH:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    log "hashing ADMIN_PASSWORD"
    ADMIN_PASSWORD_HASH=$(php -r 'echo password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_DEFAULT);')
    export ADMIN_PASSWORD_HASH
fi

# A hash that lost characters to interpolation can't verify anything, so say so
# rather than seeding an account that will never accept a password.
if [ -n "${ADMIN_PASSWORD_HASH:-}" ]; then
    php -r 'exit(password_get_info(getenv("ADMIN_PASSWORD_HASH"))["algo"] ? 0 : 1);' || {
        log "ADMIN_PASSWORD_HASH is not a valid hash — if it came from an env file, its \$ need doubling (\$\$), or use ADMIN_PASSWORD instead"
        ADMIN_PASSWORD_HASH=""
    }
fi

# AdminUserSeeder is idempotent: it creates the account, or re-activates it
# and re-syncs its password from the environment — also the recovery path if
# you lock yourself out of the initial admin account.
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD_HASH:-}" ]; then
    log "seeding the admin account (${ADMIN_EMAIL})"
    php spark db:seed AdminUserSeeder || log "admin seeding failed — check the log above"
else
    log "ADMIN_EMAIL and ADMIN_PASSWORD (or ADMIN_PASSWORD_HASH) not set — skipping admin seeding"
fi

exec "$@"
