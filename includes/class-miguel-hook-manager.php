<?php
/**
 * Hook Manager for centralized hook registration and cleanup
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Manages WordPress hooks registration and cleanup for testability
 */
class Miguel_Hook_Manager implements Miguel_Hook_Manager_Interface {

	/**
	 * Array of registered hooks for cleanup
	 *
	 * @var array
	 */
	private $registered_hooks = [];

	/**
	 * Add action hook with tracking for cleanup
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		add_action( $hook, $callback, $priority, $accepted_args );
		$this->registered_hooks[] = [
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}

	/**
	 * Add filter hook with tracking for cleanup
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		add_filter( $hook, $callback, $priority, $accepted_args );
		$this->registered_hooks[] = [
			'type'          => 'filter',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}

	/**
	 * Remove all registered hooks
	 */
	public function remove_all_hooks() {
		foreach ( $this->registered_hooks as $hook_data ) {
			if ( 'action' === $hook_data['type'] ) {
				remove_action( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			} else {
				remove_filter( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			}
		}
		$this->registered_hooks = [];
	}

	/**
	 * Get list of registered hooks (useful for testing)
	 *
	 * @return array
	 */
	public function get_registered_hooks() {
		return $this->registered_hooks;
	}

	/**
	 * Check if a specific hook is registered
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback function.
	 * @return bool
	 */
	public function is_hook_registered( $hook, $callback ) {
		foreach ( $this->registered_hooks as $hook_data ) {
			if ( $hook_data['hook'] === $hook && $hook_data['callback'] === $callback ) {
				return true;
			}
		}
		return false;
	}
}
