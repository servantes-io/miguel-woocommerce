<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Miguel
 */

define( 'MIGUEL_PROJECT_DIR', dirname( __DIR__ ));
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	throw new Exception( "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" );
}

if ( file_exists(MIGUEL_PROJECT_DIR . '/woocommerce/plugins/woocommerce/woocommerce.php') ) {
  define( 'MIGUEL_WC_DIR', MIGUEL_PROJECT_DIR . '/woocommerce/plugins/woocommerce/' );
} else {
  define( 'MIGUEL_WC_DIR', MIGUEL_PROJECT_DIR . '/woocommerce');
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require_once MIGUEL_WC_DIR . '/woocommerce.php';
	require_once MIGUEL_PROJECT_DIR . '/miguel.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Plugin helpers
require_once MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-http.php';
require_once MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-order.php';
require_once MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-product.php';

// WooCommerce helpers
if ( file_exists(MIGUEL_WC_DIR . '/tests/legacy/bootstrap.php') ) {
  require_once MIGUEL_WC_DIR . '/tests/legacy/bootstrap.php';
} else {
  require_once MIGUEL_WC_DIR . '/tests/bootstrap.php';
}

// Start up the WP testing environment.
require_once $_tests_dir . '/includes/bootstrap.php';
