#!/usr/bin/env bash
#
# Install a complete, test-ready WooCommerce for CI.
#
# Instead of cloning the WooCommerce monorepo and building it from source
# (which fails for older versions whose ancient JS/PHP toolchain no longer
# builds in modern CI), this downloads the pre-built plugin release from
# WordPress.org and fetches the matching test suite from GitHub. This mirrors
# the proven approach in bin/docker-test-entrypoint.sh.
#
# The WordPress.org release is a fully built, single-folder plugin (woocommerce.php
# at its root, with packages/ and vendor/ already present), so it always extracts
# to ./woocommerce/ regardless of the WooCommerce version's repo layout.
#
# Usage: install-woocommerce.sh <woocommerce-version> <phpunit-version>
set -euo pipefail

WC_VERSION="${1:?usage: install-woocommerce.sh <woocommerce-version> <phpunit-version>}"
PHPUNIT_VERSION="${2:-^9}"

WC_DIR="woocommerce"

if [ -d "$WC_DIR" ]; then
    echo "==> $WC_DIR already exists (restored from cache); skipping install."
    exit 0
fi

# yoast/phpunit-polyfills is a dev dependency, so it is absent from the
# production WordPress.org vendor/ but required by the WC test bootstrap.
# 2.x needs PHPUnit >= 7.5; the 1.x line is used for the legacy PHPUnit 7.5
# matrix rows, matching what those WooCommerce versions expect.
case "$PHPUNIT_VERSION" in
    7*) POLYFILLS_VERSION="1.1.1" ;;
    *)  POLYFILLS_VERSION="2.0.1" ;;
esac

echo "==> Downloading pre-built WooCommerce $WC_VERSION from WordPress.org..."
curl -sSL "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o /tmp/woocommerce.zip
unzip -q /tmp/woocommerce.zip -d .
rm -f /tmp/woocommerce.zip

if [ ! -f "$WC_DIR/woocommerce.php" ]; then
    echo "ERROR: $WC_DIR/woocommerce.php missing after extraction." >&2
    exit 1
fi

echo "==> Fetching WooCommerce $WC_VERSION test suite from GitHub..."
# The released plugin omits tests/, so sparse-clone just the test suite. Older
# (pre-monorepo) tags keep it at tests/; the monorepo keeps it at
# plugins/woocommerce/tests/. Either way it lands at woocommerce/tests/ so the
# bootstrap resolves plugin_dir back to the woocommerce/ plugin root.
WC_CLONE="/tmp/wc-tests-clone"
rm -rf "$WC_CLONE"
git clone --depth 1 --branch "$WC_VERSION" \
    --filter=blob:none --sparse \
    https://github.com/woocommerce/woocommerce.git "$WC_CLONE"
(
    cd "$WC_CLONE"
    git sparse-checkout set "plugins/woocommerce/tests" "tests"
)
if [ -d "$WC_CLONE/plugins/woocommerce/tests" ]; then
    mv "$WC_CLONE/plugins/woocommerce/tests" "$WC_DIR/tests"
elif [ -d "$WC_CLONE/tests" ]; then
    mv "$WC_CLONE/tests" "$WC_DIR/tests"
else
    echo "ERROR: could not find a tests/ directory in WooCommerce $WC_VERSION." >&2
    exit 1
fi
rm -rf "$WC_CLONE"

echo "==> Installing phpunit-polyfills $POLYFILLS_VERSION..."
# The WC bootstrap references the polyfills relative to its own location, which
# differs between the monorepo (woocommerce/vendor) and the older standalone
# layout (woocommerce/tests/vendor). Provide both so either resolves.
rm -rf /tmp/polyfills-extract
mkdir -p /tmp/polyfills-extract
curl -sSL "https://github.com/Yoast/PHPUnit-Polyfills/archive/refs/tags/${POLYFILLS_VERSION}.tar.gz" \
    -o /tmp/polyfills.tar.gz
tar xzf /tmp/polyfills.tar.gz -C /tmp/polyfills-extract
for vendor_base in "$WC_DIR/vendor" "$WC_DIR/tests/vendor"; do
    mkdir -p "$vendor_base/yoast"
    cp -R "/tmp/polyfills-extract/PHPUnit-Polyfills-${POLYFILLS_VERSION}" \
        "$vendor_base/yoast/phpunit-polyfills"
done
rm -rf /tmp/polyfills-extract /tmp/polyfills.tar.gz

# The monorepo test bootstrap reads this feature-config file, normally produced
# by the JS build. Stub it so feature detection resolves to an empty set.
if [ ! -f "$WC_DIR/client/admin/config/development.json" ]; then
    mkdir -p "$WC_DIR/client/admin/config"
    echo '{"features":{}}' > "$WC_DIR/client/admin/config/development.json"
fi

echo "==> WooCommerce $WC_VERSION ready at ./$WC_DIR"
