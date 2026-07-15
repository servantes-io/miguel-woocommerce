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
	 * Hook manager instance.
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Constructor.
	 *
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
	 * Return all configured WooCommerce shipping methods grouped by zone.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_delivery_methods( $request ) {
		$zones = $this->collect_zones();

		return new WP_REST_Response(
			array(
				'count' => count( $zones ),
				'zones' => $zones,
			),
			200
		);
	}

	/**
	 * Collect all zones with their shipping methods. Zones with no methods are omitted.
	 * Zone 0 ("Rest of World") is appended last if it has methods.
	 *
	 * @return array
	 */
	private function collect_zones() {
		$zones    = array();
		$currency = get_woocommerce_currency();

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone    = new WC_Shipping_Zone( $zone_data['id'] );
			$methods = $this->collect_zone_methods( $zone, $currency );
			if ( ! empty( $methods ) ) {
				$zones[] = $this->format_zone( $zone, $methods );
			}
		}

		// Zone 0 is "Rest of World" and is not included in get_zones().
		$rest_zone = new WC_Shipping_Zone( 0 );
		$methods   = $this->collect_zone_methods( $rest_zone, $currency );
		if ( ! empty( $methods ) ) {
			$zones[] = $this->format_zone( $rest_zone, $methods );
		}

		return $zones;
	}

	/**
	 * Format a zone with its methods for the API response.
	 *
	 * @param WC_Shipping_Zone $zone    Shipping zone.
	 * @param array            $methods Formatted methods array.
	 * @return array
	 */
	private function format_zone( $zone, $methods ) {
		return array(
			'id'        => $zone->get_id(),
			'name'      => $zone->get_zone_name(),
			'locations' => $this->format_zone_locations( $zone->get_zone_locations() ),
			'methods'   => $methods,
		);
	}

	/**
	 * Format zone locations for the API response.
	 *
	 * @param array $locations Zone location objects from WC_Shipping_Zone::get_zone_locations().
	 * @return array
	 */
	private function format_zone_locations( $locations ) {
		$result = array();
		foreach ( $locations as $loc ) {
			$result[] = array(
				'type' => $loc->type,
				'code' => $loc->code,
			);
		}
		return $result;
	}

	/**
	 * Collect formatted shipping methods for a zone.
	 *
	 * @param WC_Shipping_Zone $zone     Shipping zone.
	 * @param string           $currency ISO 4217 store currency code.
	 * @return array
	 */
	private function collect_zone_methods( $zone, $currency ) {
		$methods = array();
		foreach ( $zone->get_shipping_methods() as $method ) {
			$methods[] = $this->format_method( $method, $currency );
		}
		return $methods;
	}

	/**
	 * Format a single shipping method for the API response.
	 *
	 * @param WC_Shipping_Method $method   Shipping method instance.
	 * @param string             $currency ISO 4217 store currency code.
	 * @return array
	 */
	private function format_method( $method, $currency ) {
		return array(
			'instance_id'      => $method->get_instance_id(),
			'method_id'        => $method->id,
			'title'            => $method->get_option( 'title' ),
			'description'      => $method->get_option( 'description' ),
			'enabled'          => $method->is_enabled(),
			'currency'         => $currency,
			'cost'             => $method->get_option( 'cost', null ),
			'min_amount'       => $method->get_option( 'min_amount', null ),
			'free_shipping'    => $method->get_option( 'free_shipping', null ),
			'requires'         => $method->get_option( 'requires', null ),
			'ignore_discounts' => $method->get_option( 'ignore_discounts', null ),
		);
	}
}
