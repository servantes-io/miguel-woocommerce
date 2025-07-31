<?php
/**
 * Hook Manager Interface
 *
 * @package Miguel
 */

namespace Servantes\Miguel\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Interface for hook managers to enable dependency injection and better testability
 */
interface HookManagerInterface {

	/**
	 * Add action hook with tracking for cleanup
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 );

	/**
	 * Add filter hook with tracking for cleanup
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 );

	/**
	 * Remove all registered hooks
	 */
	public function remove_all_hooks();

	/**
	 * Get list of registered hooks (useful for testing)
	 *
	 * @return array
	 */
	public function get_registered_hooks();

	/**
	 * Check if a specific hook is registered
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback function.
	 * @return bool
	 */
	public function is_hook_registered( $hook, $callback );
}
