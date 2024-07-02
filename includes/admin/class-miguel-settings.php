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

		parent::__construct();
	}

	/**
	 * Get settings config for WooCommerce Settings page.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = array_filter(
			array(
				array(
					'id' => 'miguel_api_options',
					'type' => 'title',
					'title' => __( 'Miguel API', 'miguel' ),
					'desc' => __( 'miguel_settings_description', 'miguel' ),
				),
				array(
					'id' => 'miguel_api_key',
					'css' => 'min-width: 350px;',
					'type' => 'text',
					'title' => __( 'API key', 'miguel' ),
					'desc' => __( 'To setup safe communication between your e-shop and our server.', 'miguel' ),
				),
				array(
					'id' => 'miguel_api_options',
					'type' => 'sectionend',
				),
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
