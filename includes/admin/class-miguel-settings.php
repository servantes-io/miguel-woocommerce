<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Settings
 *
 * @package Miguel
 */
class Miguel_Settings extends WC_Settings_Page {

  /**
   * Init settings page.
   */
  public function __construct() {
    $this->id = 'miguel';
    $this->label = __( 'Miguel', 'miguel' );

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
        'id' => 'miguel_api_options',
        'type' => 'title',
        'title' => __( 'API', 'miguel' )
      ),
      array(
        'id' => 'miguel_api_url',
        'css' => 'min-width: 350px;',
        'type' => 'text',
        'title' => __( 'Url', 'miguel' ),
      ),
      array(
        'id' => 'miguel_api_token',
        'css' => 'min-width: 350px;',
        'type' => 'text',
        'title' => __( 'Token', 'miguel' ),
      ),
      array(
        'id' => 'miguel_async_gen',
        'type' => 'checkbox',
        'title' => __( 'Asynchronous generation', 'miguel' ),
        'desc' => __( 'Enable asynchronous generation', 'miguel' )
      ),
      array(
        'id' => 'miguel_testmode',
        'type' => 'checkbox',
        'title' => __( 'Testmode', 'miguel' ),
        'desc' => __( 'Enable testmode', 'miguel' )
      ),
      array(
        'id' => 'miguel_api_options',
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

return new Miguel_Settings();
