<?php
/**
 * Plugin Name: Miguel for WooCommerce
 * Plugin URI: https://www.servantes.cz/miguel
 * Description: Sell watermarked e-books and audiobook directly from WooCommerce e-shop via Miguel.
 * Requires at least: 4.9
 * Text Domain: miguel
 * Author: Servantes, s.r.o.
 * Author URI: https://servantes.cz
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

if ( ! defined( 'MIGUEL_PLUGIN_FILE' ) ) {
  define( 'MIGUEL_PLUGIN_FILE', __FILE__ );
}

if ( ! class_exists( 'Miguel' ) ) {
  include_once dirname( __FILE__ ) . '/includes/class-miguel.php';
}

function Miguel() {
  return Miguel::instance();
}

$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
if ( in_array( 'woocommerce/woocommerce.php', $active_plugins ) || class_exists( 'WooCommerce' ) ) {
  Miguel();
}
