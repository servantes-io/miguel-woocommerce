<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The main admin class.
 *
 * @package Miguel
 */
class Miguel_Admin {

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Settings page instance
	 *
	 * @var Miguel_Settings
	 */
	private $settings_page;

	/**
	 * Initialize with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 * @param Miguel_Settings     $settings_page Settings page instance.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_Settings $settings_page ) {
		$this->hook_manager  = $hook_manager;
		$this->settings_page = $settings_page;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_pages' ) );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager_Interface
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Adds settings pages
	 *
	 * @param array $pages Settings pages array.
	 * @return array
	 */
	public function add_settings_pages( $pages ) {
		if ( $this->settings_page ) {
			$pages[] = $this->settings_page;
		} else {
			// Fallback for backward compatibility
			$pages[] = include dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-settings.php';
		}
		return $pages;
	}
}
