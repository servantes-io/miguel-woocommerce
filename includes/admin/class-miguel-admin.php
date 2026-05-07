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
	 * Container instance
	 *
	 * @var Miguel_Container
	 */
	private $container;

	/**
	 * Initialize with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_Container $container ) {
		$this->hook_manager  = $hook_manager;
		$this->container = $container;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_pages' ) );
		$this->hook_manager->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
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
	 * Enqueue admin scripts on the Miguel settings tab.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' !== $hook || ! isset( $_GET['tab'] ) || 'miguel' !== $_GET['tab'] ) {
			return;
		}

		wp_enqueue_script(
			'miguel-admin',
			plugin_dir_url( MIGUEL_PLUGIN_FILE ) . 'assets/js/admin.js',
			array( 'jquery' ),
			miguel()->version,
			true
		);
	}

	/**
	 * Adds settings pages
	 *
	 * @param array $pages Settings pages array.
	 * @return array
	 */
	public function add_settings_pages( $pages ) {
		$pages[] = $this->container->get( 'settings' );
		return $pages;
	}
}
