<?php
/**
 * Plugin Name: Miguel for WooCommerce
 * Plugin URI: https://servantes.io/miguel_woocommerce
 * Description: Sell your e-books and audiobooks directly on your e-shop.
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Tested up to: 7.1
 * Text Domain: miguel
 * Domain Path: /languages
 * Author: Servantes, s.r.o.
 * Author URI: https://servantes.io
 * Version: 1.10.2
 * License: GPLv3
 *
 * WC requires at least: 7.9
 * WC tested up to: 11.2
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'MIGUEL_PLUGIN_FILE' ) ) {
	define( 'MIGUEL_PLUGIN_FILE', __FILE__ );
}

if ( ! class_exists( 'Miguel' ) ) {
	include_once __DIR__ . '/includes/class-miguel.php';
}

/**
 * Main instance of Miguel.
 */
function miguel() {
	return Miguel::instance();
}

$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
if ( in_array( 'woocommerce/woocommerce.php', $active_plugins ) || class_exists( 'WooCommerce' ) ) {
	miguel();
}
