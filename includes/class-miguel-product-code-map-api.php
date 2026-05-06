<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API for mapping Miguel product codes to WooCommerce product IDs.
 *
 * @package Miguel
 */
class Miguel_Product_Code_Map_Api {
	use Miguel_Rest_Auth_Trait;

	/**
	 * Hook manager instance.
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Product code resolver.
	 *
	 * @var Miguel_Product_Code_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 * @param Miguel_Product_Code_Resolver|null $resolver Product code resolver.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, $resolver = null ) {
		$this->hook_manager = $hook_manager;
		$this->resolver = $resolver instanceof Miguel_Product_Code_Resolver ? $resolver : new Miguel_Product_Code_Resolver();
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
			'/product-code-map',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_product_code_map' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Get product code to product ID map.
	 *
	 * @return WP_REST_Response
	 */
	public function get_product_code_map() {
		$product_code_details = $this->resolver->get_product_code_details_map();
		$duplicate_codes = array();

		foreach ( $product_code_details as $product_code => $details ) {
			if ( ! $details['is_unique'] ) {
				$duplicate_codes[] = $product_code;
			}
		}

		Miguel::debug_log(
			'Generated product code map',
			array(
				'count' => count( $product_code_details ),
				'duplicate_count' => count( $duplicate_codes ),
				'duplicate_codes' => $duplicate_codes,
			)
		);

		return new WP_REST_Response(
			array(
				'count' => count( $product_code_details ),
				'duplicate_count' => count( $duplicate_codes ),
				'product_code_map' => $this->resolver->get_product_code_map(),
				'product_code_details' => $product_code_details,
			),
			200
		);
	}

}
