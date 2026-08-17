#!/usr/bin/env bash
#
# Runs the test suite against MySQL/MariaDB in a disposable container.
#
# Development runs on SQLite and production on MySQL, so the suite has to pass
# on both before a release — see PLAN.md §6.4 for the divergences that matters
# for. The most consequential one is the collation of version_sort_key: under a
# Unicode collation, MariaDB sorts the stable-release sentinel '~' *before* the
# prerelease markers, which puts 1.0.0 ahead of 1.0.0-alpha in every listing.
#
# Usage (Docker required):
#   ./tools/test-mysql.sh [phpunit arguments...]
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="${PEPITE_MYSQL_CONTAINER:-pepite-test-mysql}"
PORT="${PEPITE_MYSQL_PORT:-33062}"
IMAGE="${PEPITE_MYSQL_IMAGE:-mariadb:11}"

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup

echo "Starting $IMAGE on port $PORT..."
docker run --rm -d --name "$NAME" \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=1 \
    -e MARIADB_DATABASE=pepite_test \
    -e MYSQL_DATABASE=pepite_test \
    -p "$PORT:3306" "$IMAGE" >/dev/null

# Probe from the host, over the published port. Probing inside the container
# would report ready too early: the entrypoint runs a temporary server with
# --skip-networking to initialise the data directory, then restarts. A client
# connected during that window is dropped with "server has gone away".
ready=0

for _ in $(seq 1 90); do
    if PEPITE_PROBE_PORT="$PORT" php -r '
        $m = @new mysqli("127.0.0.1", "root", "", "pepite_test", (int) getenv("PEPITE_PROBE_PORT"));
        exit($m->connect_errno === 0 ? 0 : 1);
    ' 2>/dev/null; then
        ready=1
        break
    fi
    sleep 1
done

if [ "$ready" -ne 1 ]; then
    echo "The database never became reachable on port $PORT." >&2
    docker logs "$NAME" 2>&1 | tail -20 >&2
    exit 1
fi

echo "Running the suite against $IMAGE..."
cd "$ROOT/src"

env \
    'database.tests.DBDriver=MySQLi' \
    'database.tests.hostname=127.0.0.1' \
    "database.tests.port=$PORT" \
    'database.tests.username=root' \
    'database.tests.password=' \
    'database.tests.database=pepite_test' \
    vendor/bin/phpunit "$@"
