#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 <new-version>"
    echo "Example: $0 1.7.0"
    exit 1
}

[[ $# -ne 1 ]] && usage

NEW_VERSION="$1"

if ! echo "$NEW_VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Error: version must be in X.Y.Z format" >&2
    exit 1
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Detect current version from the plugin header
CURRENT_VERSION=$(grep -m1 '^ \* Version:' "$REPO_ROOT/miguel.php" | sed 's/.* Version: //')

if [[ -z "$CURRENT_VERSION" ]]; then
    echo "Error: could not detect current version from miguel.php" >&2
    exit 1
fi

if [[ "$CURRENT_VERSION" == "$NEW_VERSION" ]]; then
    echo "Already at version $NEW_VERSION — nothing to do."
    exit 0
fi

echo "Bumping $CURRENT_VERSION → $NEW_VERSION"

# GNU sed takes -i with no argument; BSD sed (macOS) requires an explicit suffix and
# reads a bare -i as "the next argument is the suffix", which eats the script. Detect
# once, so this runs on a maintainer's Mac and in a Linux container alike.
if sed --version >/dev/null 2>&1; then
    sed_inplace() { sed -i "$@"; }
else
    sed_inplace() { sed -i '' "$@"; }
fi

# miguel.php — plugin header
sed_inplace "s/ \* Version: $CURRENT_VERSION/ * Version: $NEW_VERSION/" "$REPO_ROOT/miguel.php"

# readme.txt — Stable tag
sed_inplace "s/^Stable tag: $CURRENT_VERSION$/Stable tag: $NEW_VERSION/" "$REPO_ROOT/readme.txt"

# includes/class-miguel.php — $version property
sed_inplace "s/\(public \\\$version = '\)$CURRENT_VERSION\(';.*\)/\1$NEW_VERSION\2/" "$REPO_ROOT/includes/class-miguel.php"

# README.md — Version badge line
sed_inplace "s/^- \*\*Version:\*\* $CURRENT_VERSION$/- **Version:** $NEW_VERSION/" "$REPO_ROOT/README.md"

echo "Done. Verify with: grep -rn '$NEW_VERSION' miguel.php readme.txt README.md includes/class-miguel.php"
