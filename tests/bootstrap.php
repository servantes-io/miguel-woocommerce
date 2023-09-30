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

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require MIGUEL_PROJECT_DIR . '/woocommerce/plugins/woocommerce/woocommerce.php';
	require MIGUEL_PROJECT_DIR . '/miguel.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Plugin helpers
require MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-http.php';
require MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-order.php';
require MIGUEL_PROJECT_DIR . '/tests/helpers/class-miguel-helper-product.php';

// WooCommerce helpers
$wc_tests_framework_base_dir = MIGUEL_PROJECT_DIR . '/woocommerce/plugins/woocommerce/tests/legacy/framework/';
require_once $wc_tests_framework_base_dir . 'helpers/class-wc-helper-shipping.php';
require_once $wc_tests_framework_base_dir . 'helpers/class-wc-helper-product.php';
require_once $wc_tests_framework_base_dir . 'helpers/class-wc-helper-order.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
