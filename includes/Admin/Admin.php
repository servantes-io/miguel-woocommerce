<?php
/**
 * The main admin class.
 *
 * @package Miguel
 */

namespace Servantes\Miguel\Admin;

use Servantes\Miguel\Interfaces\HookManagerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The main admin class.
 *
 * @package Miguel
 */
class Admin {

	/**
	 * Hook manager instance
	 *
	 * @var HookManagerInterface
	 */
	private $hook_manager;

	/**
	 * Settings page instance
	 *
	 * @var Settings
	 */
	private $settings_page;

	/**
	 * Initialize with dependency injection
	 *
	 * @param HookManagerInterface $hook_manager Hook manager for registering actions.
	 * @param Settings             $settings_page Settings page instance.
	 */
	public function __construct( HookManagerInterface $hook_manager, Settings $settings_page ) {
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
	 * @return HookManagerInterface
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
		$pages[] = $this->settings_page;
		return $pages;
	}
}
