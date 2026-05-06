#!/usr/bin/env bash
set -e

WC_VERSION=${WC_VERSION:-9.9.5}
WP_VERSION=${WP_VERSION:-6.8.5}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
DB_HOST="woocommerce_test_db"
DB_NAME="woocommerce_tests"
DB_USER="root"
DB_PASS="woocommerce_test_password"

echo "==> Waiting for MySQL at $DB_HOST..."
until mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SELECT 1" 2>/dev/null; do
    sleep 2
done
echo "==> MySQL ready."

if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
    echo "==> Installing WordPress test library (WP $WP_VERSION)..."
    /project/bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION" false
    echo "==> WordPress test library ready."
fi

setup_woocommerce_monorepo() {
    local wc_plugin_dir="/project/woocommerce/plugins/woocommerce"
    if [ -f "$wc_plugin_dir/woocommerce.php" ]; then
        return 0
    fi
    echo "==> Downloading WooCommerce $WC_VERSION (monorepo)..."
    local tmpfile
    tmpfile=$(mktemp /tmp/wc-XXXXXX.tar.gz)
    curl -sL "https://github.com/woocommerce/woocommerce/archive/refs/tags/${WC_VERSION}.tar.gz" -o "$tmpfile"
    mkdir -p /tmp/wc-extract
    tar xzf "$tmpfile" --wildcards "woocommerce-${WC_VERSION}/plugins/woocommerce/*" \
        -C /tmp/wc-extract 2>/dev/null || \
    tar xzf "$tmpfile" -C /tmp/wc-extract
    local extracted_dir
    extracted_dir=$(ls /tmp/wc-extract/)
    mkdir -p /project/woocommerce/plugins
    mv "/tmp/wc-extract/$extracted_dir/plugins/woocommerce" "$wc_plugin_dir"
    rm -f "$tmpfile"
    rm -rf /tmp/wc-extract
    echo "==> Running composer install for WooCommerce..."
    composer install --working-dir="$wc_plugin_dir" --no-dev --prefer-dist --no-interaction --no-progress
    echo "==> WooCommerce ready."
}

setup_woocommerce_standalone() {
    local wc_dir="/project/woocommerce"
    if [ -f "$wc_dir/woocommerce.php" ]; then
        return 0
    fi
    echo "==> Downloading WooCommerce $WC_VERSION (standalone)..."
    local tmpfile
    tmpfile=$(mktemp /tmp/wc-XXXXXX.tar.gz)
    curl -sL "https://github.com/woocommerce/woocommerce/archive/refs/tags/${WC_VERSION}.tar.gz" -o "$tmpfile"
    mkdir -p /tmp/wc-extract
    tar xzf "$tmpfile" -C /tmp/wc-extract
    local extracted_dir
    extracted_dir=$(ls /tmp/wc-extract/)
    mv "/tmp/wc-extract/$extracted_dir"/* "$wc_dir/"
    rm -f "$tmpfile"
    rm -rf /tmp/wc-extract
    if [ -f "$wc_dir/composer.json" ]; then
        echo "==> Running composer install for WooCommerce..."
        composer install --working-dir="$wc_dir" --no-dev --prefer-dist --no-interaction --no-progress
    fi
    echo "==> WooCommerce ready."
}

if [ ! -f "/project/woocommerce/plugins/woocommerce/woocommerce.php" ] && \
   [ ! -f "/project/woocommerce/woocommerce.php" ]; then
    # Peek at the archive to detect monorepo vs standalone structure
    tmpfile=$(mktemp /tmp/wc-peek-XXXXXX.tar.gz)
    curl -sL "https://github.com/woocommerce/woocommerce/archive/refs/tags/${WC_VERSION}.tar.gz" -o "$tmpfile"
    if tar tzf "$tmpfile" 2>/dev/null | grep -q "plugins/woocommerce/woocommerce.php"; then
        rm -f "$tmpfile"
        setup_woocommerce_monorepo
    else
        rm -f "$tmpfile"
        setup_woocommerce_standalone
    fi
fi

cd /project
exec vendor/bin/phpunit -c tests/phpunit.dist.xml "$@"
