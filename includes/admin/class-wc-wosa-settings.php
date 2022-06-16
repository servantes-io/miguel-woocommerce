<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Settings 
 *
 * @package WC_Wosa
 */
class WC_Wosa_Settings extends WC_Settings_Page {

  /**
   * Init settings page. 
   */
  public function __construct() {    
    $this->id = 'wosa';
    $this->label = __( 'Wosa', 'wc-wosa' );

    add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
    add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
    add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
  }

  /**
   * @return array
   */
  public function get_settings() {
    $settings = array( 
      array(
        'id' => 'wc_wosa_api_options',
        'type' => 'title', 
        'title' => __( 'API', 'wc-wosa' )
      ),
      array(
        'id' => 'wc_wosa_api_url',
        'css' => 'min-width: 350px;',
        'type' => 'text',
        'title' => __( 'Url', 'wc-wosa' ),
      ),
      array(
        'id' => 'wc_wosa_api_token',
        'css' => 'min-width: 350px;',
        'type' => 'text',
        'title' => __( 'Token', 'wc-wosa' ),
      ),
      array(
        'id' => 'wc_wosa_async_gen',
        'type' => 'checkbox',
        'title' => __( 'Asynchronous generation', 'wc-wosa' ),
        'desc' => __( 'Enable asynchronous generation', 'wc-wosa' )
      ),
      array(
        'id' => 'wc_wosa_testmode',
        'type' => 'checkbox',
        'title' => __( 'Testmode', 'wc-wosa' ),
        'desc' => __( 'Enable testmode', 'wc-wosa' )
      ),
      array(
        'id' => 'wc_wosa_api_options',
        'type' => 'sectionend'
      )
    );

    return $settings;
  }

  /**
   * Display settings.
   */
  public function output() {
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WC_Admin_Settings::output_fields( $settings );
  }

  /**
   * Save settings.
   */
  public function save() {
    global $current_section;

    $settings = $this->get_settings( $current_section );
    WC_Admin_Settings::save_fields( $settings );
  }
}

return new WC_Wosa_Settings();
