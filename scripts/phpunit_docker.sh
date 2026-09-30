#!/usr/bin/env bash
#
# phpunit_docker.sh — run this plugin's whole PHPUnit suite in Docker, the way
# `make test-docker` does, on a machine that has Docker but no PHP toolchain.
#
# It takes NO arguments, on purpose. The youtrack-triage implement loop runs it
# by name as this repository's test command, admits it only without arguments,
# and before running it requires this file, docker-compose.test.yml,
# Dockerfile.test, bin/docker-test-entrypoint.sh and bin/install-wp-tests.sh to
# be byte-identical to origin/main — so a branch cannot change what "run the
# tests" means. For a filtered run while developing, call compose directly:
#   docker compose -f docker-compose.test.yml run --rm phpunit --filter X
#
# What it does, in order:
#   1. takes a lock, so two runs never share one test database;
#   2. installs the plugin's own composer dependencies inside the container —
#      vendor/ is gitignored, so a fresh checkout or worktree has none, and the
#      entrypoint execs vendor/bin/phpunit from the mounted tree;
#   3. runs the suite through the entrypoint (which installs WordPress and
#      WooCommerce into cached volumes on first use);
#   4. brings the compose project down on any exit, including TERM from a
#      runner's timeout, so no MySQL container is left behind. Volumes are
#      kept: they are the WordPress/WooCommerce caches that make runs fast.
#
# The compose project name is fixed (not the directory name) so the cache
# volumes are shared by every checkout and worktree, and so `down` can never
# touch a developer's own stack, which runs under the default project name.
set -euo pipefail

if [ "$#" -ne 0 ]; then
    echo "phpunit_docker.sh takes no arguments (see the header for a filtered run)" >&2
    exit 2
fi

cd "$(dirname "$0")/.."

PROJECT="miguel-woocommerce-phpunit"
COMPOSE=(docker compose -f docker-compose.test.yml -p "$PROJECT")

exec 9>"${TMPDIR:-/tmp}/${PROJECT}.lock"
flock 9

cleanup() { "${COMPOSE[@]}" down --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
trap 'exit 143' TERM INT

echo "==> composer install"
"${COMPOSE[@]}" run --build --rm --no-deps --entrypoint composer phpunit \
    install --no-interaction --no-progress --prefer-dist

echo "==> phpunit"
"${COMPOSE[@]}" run --rm phpunit
