<?php
/**
 * Plugin Name: Miguel for WooCommerce
 * Plugin URI: https://www.servantes.cz/en/miguel
 * Description: Sell watermarked e-books and audiobooks directly from WooCommerce e-shop via Miguel.
 * Requires at least: 4.9
 * Text Domain: miguel
 * Author: Servantes, s.r.o.
 * Author URI: https://servantes.cz
 * Version: 1.1.3
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
