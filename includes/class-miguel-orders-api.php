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

		register_rest_route(
			'miguel/v1',
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
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
	 * Return a single order by ID with a richer detail view.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_order( $request ) {
		$order_id = absint( $request->get_param( 'id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error(
				'order.not_found',
				esc_html__( 'Order was not found.', 'miguel' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->format_order_detail( $order ), 200 );
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
	 * Format a single order with the richer detail field set.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_order_detail( $order ) {
		return array_merge(
			$this->format_order( $order ),
			array(
				'line_items'     => $this->format_line_items( $order ),
				'total'          => wc_format_decimal( $order->get_total() ),
				'subtotal'       => wc_format_decimal( $order->get_subtotal() ),
				'total_tax'      => wc_format_decimal( $order->get_total_tax() ),
				'shipping_total' => wc_format_decimal( $order->get_shipping_total() ),
				'discount_total' => wc_format_decimal( $order->get_discount_total() ),
			)
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
			$codes = $this->get_miguel_codes_for_item( $item );
			if ( empty( $codes ) ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false ); // exc. tax, exc. rounding

			foreach ( $codes as $code ) {
				$products[] = array(
					'code'  => $code,
					'price' => array(
						'sold_without_vat' => $item_total,
					),
				);
			}
		}

		return $products;
	}

	/**
	 * Collect Miguel product codes from a single order line item.
	 * Returns an empty array for non-product, non-downloadable, or non-Miguel items.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @return array List of Miguel codes (strings).
	 */
	private function get_miguel_codes_for_item( $item ) {
		$codes = array();

		if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
			return $codes;
		}

		$product = $item->get_product();
		if ( ! $product || ! $product->is_downloadable() ) {
			return $codes;
		}

		foreach ( $product->get_downloads() as $download ) {
			$file = is_array( $download )
				? ( isset( $download['file'] ) ? $download['file'] : '' )
				: ( method_exists( $download, 'get_file' ) ? $download->get_file() : '' );

			if ( empty( $file ) || ! Miguel_Order_Utils::is_miguel_shortcode( $file ) ) {
				continue;
			}

			$code = Miguel_Order_Utils::extract_miguel_code( $file );
			if ( $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Format all product line items of an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function format_line_items( $order ) {
		$line_items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			$codes   = $this->get_miguel_codes_for_item( $item );

			$line_items[] = array(
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'quantity'   => $item->get_quantity(),
				'total'      => wc_format_decimal( $item->get_total() ),
				'tax'        => wc_format_decimal( $item->get_total_tax() ),
				'code'       => ! empty( $codes ) ? $codes[0] : null,
			);
		}

		return $line_items;
	}
}
