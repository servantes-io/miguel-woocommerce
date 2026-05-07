<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST API for listing changed WooCommerce orders.
 *
 * @package Miguel
 */
class Miguel_Orders_Api {
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
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_orders' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Return all orders modified on or after updated_since.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_orders( $request ) {
		$updated_since = $request->get_param( 'updated_since' );

		if ( empty( $updated_since ) ) {
			return new WP_Error(
				'argument.missing',
				esc_html__( 'The updated_since parameter is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$timestamp = strtotime( $updated_since );
		// strtotime returns false for unparseable strings; valid timestamps including 0 (epoch) are accepted.
		if ( false === $timestamp ) {
			return new WP_Error(
				'argument.invalid',
				esc_html__( 'The updated_since parameter is not a valid date.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$wc_orders = wc_get_orders(
			array(
				'date_modified' => '>=' . $timestamp,
				'limit'         => -1,
				'type'          => 'shop_order',
				'orderby'       => 'date_modified',
				'order'         => 'ASC',
			)
		);

		$orders = array();
		foreach ( $wc_orders as $order ) {
			$orders[] = $this->format_order( $order );
		}

		return new WP_REST_Response(
			array(
				'count'  => count( $orders ),
				'orders' => $orders,
			),
			200
		);
	}

	/**
	 * Format a single WooCommerce order for the API response.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_order( $order ) {
		$update_date = $order->get_date_modified();

		return array(
			'id'            => strval( $order->get_id() ),
			'status'        => $order->get_status(),
			'currency_code' => $order->get_currency(),
			'paid'          => $order->is_paid(),
			'purchase_date' => Miguel_Order_Utils::get_purchase_date_for_order( $order ),
			'update_date'   => $update_date ? $update_date->format( DateTime::ATOM ) : null,
			'user'          => Miguel_Order_Utils::get_user_data_for_order( $order, true ),
			'products'      => $this->collect_products_from_order( $order ),
		);
	}

	/**
	 * Extract Miguel product codes and prices from an order.
	 * Only downloadable line items with a Miguel shortcode are included; other items are omitted.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function collect_products_from_order( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->is_downloadable() ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false ); // exc. tax, exc. rounding

			foreach ( $product->get_downloads() as $download ) {
				$file = is_array( $download )
					? ( isset( $download['file'] ) ? $download['file'] : '' )
					: ( method_exists( $download, 'get_file' ) ? $download->get_file() : '' );

				if ( empty( $file ) || ! Miguel_Order_Utils::is_miguel_shortcode( $file ) ) {
					continue;
				}

				$code = Miguel_Order_Utils::extract_miguel_code( $file );
				if ( $code ) {
					$products[] = array(
						'code'  => $code,
						'price' => array(
							'sold_without_vat' => $item_total,
						),
					);
				}
			}
		}

		return $products;
	}
}
