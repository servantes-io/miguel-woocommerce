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
	 *
	 * @param array $pages
	 */
	public function add_settings_pages( $pages ) {
		$pages[] = include dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-settings.php';
		return $pages;
	}
}

return new Miguel_Admin();
