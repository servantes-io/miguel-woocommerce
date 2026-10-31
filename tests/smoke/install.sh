#!/bin/sh
#
# Runs in the wp-cli container of docker-compose.smoke.yml: installs WordPress $WP_VERSION,
# WooCommerce $WC_VERSION from wordpress.org and the plugin from /smoke/miguel.zip, the way a
# shop installs it, then seeds what tests/smoke/checks.sh needs.
set -eu

# The image's 128M PHP limit is too small to unpack WooCommerce.
wp() { php -d memory_limit=512M /usr/local/bin/wp --allow-root --path=/var/www/html "$@"; }

wp core download --version="$WP_VERSION" --force
wp config create --dbname=wordpress --dbuser=root --dbpass=smoke --dbhost=db --skip-check --force
# PHP errors go to wp-content/debug.log, which scripts/smoke_docker.sh scans after the checks.
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
wp config set DISABLE_WP_CRON true --raw

tries=0
# shellcheck disable=SC2016 # PHP code, not a shell expansion
until php -r 'try { mysqli_connect( "db", "root", "smoke" ); } catch ( Throwable $e ) { exit( 1 ); }' 2>/dev/null; do
    tries=$((tries + 1))
    [ "$tries" -lt 60 ] || { echo "MySQL did not come up" >&2; exit 1; }
    sleep 2
done

wp core install --url=http://wordpress --title="Miguel smoke" --admin_user=admin \
    --admin_password=smoke --admin_email=smoke@example.com --skip-email
wp plugin install woocommerce --version="$WC_VERSION" --activate
wp plugin install /smoke/miguel.zip --activate
# The first command that loads both plugins in WordPress's own order (miguel/ before woocommerce/).
wp eval-file /smoke/tests/seed.php

# From here on the shop must not call out: Miguel's error reporter, WooCommerce's marketplace.
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw
chown -R 33:33 /var/www/html
