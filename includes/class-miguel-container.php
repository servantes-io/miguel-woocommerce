<?php
/**
 * Simple service container for dependency injection
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Simple dependency injection container
 */
class Miguel_Container {

	/**
	 * Service definitions
	 *
	 * @var array
	 */
	private $services = [];

	/**
	 * Service instances cache
	 *
	 * @var array
	 */
	private $instances = [];

	/**
	 * Register a service factory
	 *
	 * @param string   $name    Service name.
	 * @param callable $factory Factory function.
	 */
	public function register( $name, $factory ) {
		$this->services[ $name ] = $factory;
	}

	/**
	 * Get a service instance (singleton)
	 *
	 * @param string $name Service name.
	 * @return mixed
	 * @throws Exception If service not found.
	 */
	public function get( $name ) {
		if ( ! isset( $this->instances[ $name ] ) ) {
			if ( ! isset( $this->services[ $name ] ) ) {
				throw new Exception( "Service {$name} not found" );
			}
			$this->instances[ $name ] = call_user_func( $this->services[ $name ], $this );
		}
		return $this->instances[ $name ];
	}

	/**
	 * Reset all instances (useful for testing)
	 */
	public function reset() {
		$this->instances = [];
	}

	/**
	 * Check if service is registered
	 *
	 * @param string $name Service name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->services[ $name ] );
	}

	/**
	 * Set a service instance directly (useful for testing)
	 *
	 * @param string $name     Service name.
	 * @param mixed  $instance Service instance.
	 */
	public function set( $name, $instance ) {
		$this->instances[ $name ] = $instance;
	}
}
