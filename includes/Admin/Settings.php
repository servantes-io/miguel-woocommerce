<?php
/**
 * Settings with dependency injection for better testability
 *
 * @package Miguel
 */

namespace Servantes\Miguel\Admin;

use Servantes\Miguel\Interfaces\HookManagerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings with dependency injection for better testability
 *
 * @package Miguel
 */
class Settings extends \WC_Settings_Page {

	/**
	 * Hook manager instance
	 *
	 * @var HookManagerInterface
	 */
	private $hook_manager;

	/**
	 * Init settings page with dependency injection
	 *
	 * @param HookManagerInterface $hook_manager Hook manager for registering actions.
	 */
	public function __construct( HookManagerInterface $hook_manager ) {
		$this->hook_manager = $hook_manager;
		$this->id = 'miguel';
		$this->label = __( 'Miguel', 'miguel' );

		parent::__construct();
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		$this->hook_manager->add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		$this->hook_manager->add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return HookManagerInterface|null
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
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
		\WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings.
	 */
	public function save() {
		global $current_section;

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}
}
