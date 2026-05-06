<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST API for WooCommerce delivery methods.
 *
 * @package Miguel
 */
class Miguel_Delivery_Methods_Api {
	use Miguel_Rest_Auth_Trait;

	/**
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'miguel/v1',
			'/delivery-methods',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_delivery_methods' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Return all configured WooCommerce shipping methods across all zones.
	 *
	 * @return WP_REST_Response
	 */
	public function get_delivery_methods() {
		$methods = $this->collect_delivery_methods();

		return new WP_REST_Response(
			array(
				'count'   => count( $methods ),
				'methods' => $methods,
			),
			200
		);
	}

	/**
	 * Collect shipping methods from all zones including "Rest of World".
	 *
	 * @return array
	 */
	private function collect_delivery_methods() {
		$methods = array();

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone = new WC_Shipping_Zone( $zone_data['id'] );
			foreach ( $zone->get_shipping_methods() as $method ) {
				$methods[] = $this->format_method( $method, $zone );
			}
		}

		// Zone 0 is "Rest of World" and is not included in get_zones().
		$rest_zone = new WC_Shipping_Zone( 0 );
		foreach ( $rest_zone->get_shipping_methods() as $method ) {
			$methods[] = $this->format_method( $method, $rest_zone );
		}

		return $methods;
	}

	/**
	 * Format a single shipping method for the API response.
	 *
	 * @param WC_Shipping_Method $method Shipping method instance.
	 * @param WC_Shipping_Zone   $zone   Zone the method belongs to.
	 * @return array
	 */
	private function format_method( $method, $zone ) {
		return array(
			'instance_id' => $method->get_instance_id(),
			'method_id'   => $method->id,
			'title'       => $method->get_title(),
			'enabled'     => 'yes' === $method->enabled,
			'zone_id'     => $zone->get_id(),
			'zone_name'   => $zone->get_zone_name(),
		);
	}
}
