#!/usr/bin/env bash
#
# smoke_docker.sh — install this plugin into a real WordPress + WooCommerce shop in Docker and
# check that the shop works with it, the way a store owner would install it.
#
# The PHPUnit suite loads WooCommerce before the plugin, so it cannot see what breaks when
# WordPress loads 'miguel/' first (a class extending a WooCommerce class, included on load, killed
# every page of a real shop). This runs the real order:
#   1. builds miguel.zip with `make pack`, in a container;
#   2. installs WordPress, WooCommerce from wordpress.org and the zip with wp-cli, activates both
#      and seeds a book, a coupon and an API key (tests/smoke/install.sh, tests/smoke/seed.php);
#   3. opens the front end, wp-admin pages and Miguel's REST API over HTTP, including a real
#      coupon preview (tests/smoke/checks.sh);
#   4. fails on any PHP fatal in wp-content/debug.log, and on any PHP warning, notice or
#      deprecation raised in the plugin's own files.
#
# Versions come from the environment (defaults: the newest leg of tests.yml):
#   SMOKE_PHP_VERSION=8.1 SMOKE_WP_VERSION=6.5.8 SMOKE_WC_VERSION=7.9.1 scripts/smoke_docker.sh
#
# The shop is thrown away on exit; only wp-cli's download cache volume is kept.
set -euo pipefail

cd "$(dirname "$0")/.."

PROJECT="miguel-woocommerce-smoke"
# --env-file /dev/null: versions come from this environment only, never from a .env in the checkout.
COMPOSE=(docker compose --env-file /dev/null -f docker-compose.smoke.yml -p "$PROJECT")

# One shop at a time: every run uses the same compose project.
exec 9>"/tmp/${PROJECT}.lock"
flock 9

cleanup() {
    "${COMPOSE[@]}" down --remove-orphans >/dev/null 2>&1 || true
    docker volume rm "${PROJECT}_site" >/dev/null 2>&1 || true
}
cleanup
trap cleanup EXIT
trap 'exit 143' TERM INT

echo "==> make pack"
pack_log=$(HOST_UID=$(id -u) HOST_GID=$(id -g) "${COMPOSE[@]}" run --rm --no-deps pack 2>&1) ||
    { echo "$pack_log" >&2; exit 1; }

echo "==> install WordPress ${SMOKE_WP_VERSION:-7.1.3}, WooCommerce ${SMOKE_WC_VERSION:-11.2.1}, Miguel (PHP ${SMOKE_PHP_VERSION:-8.3})"
"${COMPOSE[@]}" up -d --quiet-pull db
"${COMPOSE[@]}" run --rm cli
"${COMPOSE[@]}" up -d --quiet-pull wordpress

echo "==> smoke checks"
status=0
"${COMPOSE[@]}" run --rm checks || status=1

echo "==> PHP errors in wp-content/debug.log"
log=$("${COMPOSE[@]}" run --rm --no-deps --entrypoint sh cli -c \
    'cat /var/www/html/wp-content/debug.log 2>/dev/null || true')
fatals=$(grep -E 'PHP (Fatal|Parse) error' <<<"$log" || true)
own=$(grep -E 'PHP (Warning|Notice|Deprecated)' <<<"$log" | grep -F '/plugins/miguel/' || true)
if [ -n "$fatals" ] || [ -n "$own" ]; then
    printf '%s\n' "$fatals" "$own" | grep -v '^$' >&2
    status=1
else
    echo "ok: no fatal, and nothing from the plugin's files"
fi

exit "$status"
