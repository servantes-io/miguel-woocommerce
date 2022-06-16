<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * The main admin class. 
 *
 * @package WC_Wosa
 */
class WC_Wosa_Admin {

  /**
   * Initialize.
   */
  public function __construct() {
    $this->init_hooks();
  }

  /**
   * Inits hooks.
   */
  public function init_hooks() {
    add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_pages' ) );
  }

  /**
   * Adds settings pages
   */
  public function add_settings_pages( $pages ) {
    $pages[] = include( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/admin/class-wc-wosa-settings.php' );
    return $pages;
  }
}

return new WC_Wosa_Admin();
