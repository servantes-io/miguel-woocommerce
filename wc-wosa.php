<?php
/**
 * Plugin Name: WooCommerce Wosa
 * Plugin URI: https://doctype.cz/pluginy/wc-wosa/
 * Description: Connected to the WOSA service for generating e-books. 
 * Author: DOCTYPE, s.r.o.
 * Author URI: https://doctype.cz
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

if ( ! defined( 'WC_WOSA_PLUGIN_FILE' ) ) {
  define( 'WC_WOSA_PLUGIN_FILE', __FILE__ );
}

if ( ! class_exists( 'WC_Wosa' ) ) {
  include_once dirname( __FILE__ ) . '/includes/class-wc-wosa.php';
}

function WC_Wosa() {
  return WC_Wosa::instance();
}

$active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
if ( in_array( 'woocommerce/woocommerce.php', $active_plugins ) || class_exists( 'WooCommerce' ) ) {
  WC_Wosa();
}
