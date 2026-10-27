<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API that shows Miguel what a WooCommerce coupon does to a cart.
 *
 * Miguel keeps no coupon definitions and no coupon logic: it sends the cart, the shop prices it in
 * a throwaway WooCommerce cart with and without the coupon, and Miguel shows the difference.
 * Nothing is saved.
 *
 * @package Miguel
 */
class Miguel_Coupon_Preview_Api {
	use Miguel_Rest_Auth_Trait;

	/**
	 * Hook manager instance.
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private Miguel_Hook_Manager_Interface $hook_manager;

	/**
	 * Product code resolver.
	 *
	 * @var Miguel_Product_Code_Resolver
	 */
	private Miguel_Product_Code_Resolver $resolver;

	/**
	 * Cart probe.
	 *
	 * @var Miguel_Coupon_Cart_Probe
	 */
	private Miguel_Coupon_Cart_Probe $probe;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface     $hook_manager Hook manager.
	 * @param Miguel_Product_Code_Resolver|null $resolver Product code resolver.
	 * @param Miguel_Coupon_Cart_Probe|null     $probe Cart probe.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, $resolver = null, $probe = null ) {
		$this->hook_manager = $hook_manager;
		$this->resolver     = $resolver instanceof Miguel_Product_Code_Resolver ? $resolver : new Miguel_Product_Code_Resolver();
		$this->probe        = $probe instanceof Miguel_Coupon_Cart_Probe ? $probe : new Miguel_Coupon_Cart_Probe();
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
			'/coupons/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_coupon' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Show what a coupon code does to a cart.
	 *
	 * The cart is priced twice in a throwaway WooCommerce cart, without and with the coupon. A
	 * coupon the shop refuses is a normal answer (200 with `applied` false), not an error.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_coupon( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$code = isset( $payload['code'] ) && is_string( $payload['code'] ) ? wc_format_coupon_code( wc_clean( $payload['code'] ) ) : '';
		if ( '' === $code ) {
			return new WP_Error(
				'coupon.code_required',
				esc_html__( 'Coupon code is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$sent_lines = $this->get_valid_lines( $payload['line_items'] ?? null );
		if ( null === $sent_lines ) {
			return new WP_Error(
				'coupon.line_items_invalid',
				esc_html__( 'Coupon line_items must be a non-empty array of objects with product_code and a positive integer quantity.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$customer = $this->build_customer( $payload );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$lines = array();
		foreach ( $sent_lines as $sent_line ) {
			$resolved = $this->resolver->resolve_product_code( $sent_line['product_code'] );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$lines[] = array(
				'product_id' => (int) $resolved['product_id'],
				'quantity'   => $sent_line['quantity'],
			);
		}

		try {
			$before = $this->probe->price( $lines, null, $customer );
			$after  = $this->probe->price( $lines, $code, $customer );
		} catch ( Throwable $e ) {
			Miguel::debug_log( 'Coupon preview failed', array( 'error' => $e->getMessage() ) );

			return new WP_Error(
				'coupon.preview_failed',
				esc_html__( 'The shop could not price the cart.', 'miguel' ),
				array( 'status' => 500 )
			);
		}

		$coupon = new WC_Coupon( $code );

		return new WP_REST_Response( $this->build_response( $code, $coupon, $sent_lines, $before, $after, $customer ), 200 );
	}

	/**
	 * Assemble the response body from the two snapshots.
	 *
	 * @param string    $code       Formatted coupon code.
	 * @param WC_Coupon $coupon     The coupon.
	 * @param array     $sent_lines Lines from get_valid_lines().
	 * @param array     $before     Snapshot without the coupon.
	 * @param array     $after      Snapshot with the coupon.
	 * @param array     $customer   Customer descriptor.
	 * @return array
	 */
	private function build_response( $code, $coupon, array $sent_lines, array $before, array $after, array $customer ) {
		$applied = in_array( $code, $after['coupons'], true );
		$notices = array_values( array_unique( $after['notices'] ) );

		$error_code = null;
		$message    = null;
		if ( ! $applied ) {
			$error_code = (int) ( $after['error_code'] ?? 0 );
			$message    = $after['message'] ?? ( ! empty( $notices ) ? end( $notices ) : __( 'The coupon was removed from the cart.', 'miguel' ) );
		}

		$lines         = array();
		$removed_lines = array();
		foreach ( $sent_lines as $index => $sent_line ) {
			$line_before = $before['lines'][ $index ];
			$line_after  = $after['lines'][ $index ];
			$discount    = round( $line_before['total'] - $line_after['total'], wc_get_price_decimals() );

			$lines[] = array(
				'product_code'     => $sent_line['product_code'],
				'quantity'         => $sent_line['quantity'],
				'in_cart_quantity' => $line_after['in_cart_quantity'],
				'before'           => $this->price_cell( $line_before ),
				'after'            => $this->price_cell( $line_after ),
				'discount'         => $discount,
				'discounted'       => $discount > 0,
			);

			if ( $line_after['in_cart_quantity'] < $line_before['in_cart_quantity'] ) {
				$removed_lines[] = array(
					'product_code' => $sent_line['product_code'],
					'quantity'     => $line_before['in_cart_quantity'] - $line_after['in_cart_quantity'],
				);
			}
		}

		return array(
			'code'          => $code,
			'applied'       => $applied,
			'error_code'    => $error_code,
			'message'       => $message,
			'currency'      => get_woocommerce_currency(),
			'coupon'        => $this->describe_coupon( $coupon ),
			'lines'         => $lines,
			'added_lines'   => $this->get_added_lines( $before, $after ),
			'removed_lines' => $removed_lines,
			'gift_choices'  => null,
			'totals'        => array(
				'before' => $before['totals'],
				'after'  => $after['totals'],
			),
			'other_coupons' => array_values( array_diff( array_unique( array_merge( $before['coupons'], $after['coupons'] ) ), array( $code ) ) ),
			'unchecked'     => $this->unchecked_restrictions( $coupon, $customer ),
			'notices'       => $notices,
		);
	}

	/**
	 * The coupon's own terms, or null when the shop has no such coupon.
	 *
	 * @param WC_Coupon $coupon The coupon.
	 * @return array|null
	 */
	private function describe_coupon( $coupon ) {
		if ( 0 === (int) $coupon->get_id() ) {
			return null;
		}

		$expires = $coupon->get_date_expires();

		return array(
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => (float) $coupon->get_amount(),
			'description'   => $coupon->get_description(),
			'free_shipping' => (bool) $coupon->get_free_shipping(),
			'expires_at'    => $expires ? $expires->format( DATE_ATOM ) : null,
		);
	}

	/**
	 * The unit price, total and tax of one line snapshot.
	 *
	 * @param array $cell Line snapshot from the probe.
	 * @return array
	 */
	private function price_cell( array $cell ) {
		return array(
			'unit_price' => $cell['unit_price'],
			'total'      => $cell['total'],
			'tax'        => $cell['tax'],
		);
	}

	/**
	 * Cart lines the coupon added (a Smart Coupons gift): present after, absent before.
	 *
	 * @param array $before Snapshot without the coupon.
	 * @param array $after  Snapshot with the coupon.
	 * @return array
	 */
	private function get_added_lines( array $before, array $after ) {
		$before_keys = array_column( $before['extra'], 'key' );

		$added = array();
		foreach ( $after['extra'] as $extra ) {
			if ( in_array( $extra['key'], $before_keys, true ) ) {
				continue;
			}

			$product = wc_get_product( $extra['product_id'] );
			$added[] = array(
				'product_code' => $this->get_product_code( $extra['product_id'] ),
				'product_id'   => $extra['product_id'],
				'sku'          => $product ? (string) $product->get_sku() : '',
				'name'         => $product ? $product->get_name() : '',
				'quantity'     => $extra['quantity'],
				'unit_price'   => $extra['unit_price'],
				'total'        => $extra['total'],
				'tax'          => $extra['tax'],
				'source'       => empty( $extra['source'] ) ? 'unknown' : 'smart_coupons_action',
			);
		}

		return $added;
	}

	/**
	 * The Miguel product code of a product, if it has one.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string|null
	 */
	private function get_product_code( $product_id ) {
		foreach ( $this->resolver->get_product_code_details_map() as $product_code => $details ) {
			if ( in_array( $product_id, $details['product_ids'], true ) ) {
				return (string) $product_code;
			}
		}

		return null;
	}

	/**
	 * The request's lines, or null when any of them is malformed.
	 *
	 * A sent `price` is ignored: the shop prices the books itself.
	 *
	 * @param mixed $line_items Value of line_items in the request.
	 * @return array|null Lines with a trimmed product_code and an int quantity.
	 */
	private function get_valid_lines( $line_items ) {
		if ( ! is_array( $line_items ) || empty( $line_items ) ) {
			return null;
		}

		$lines = array();
		foreach ( $line_items as $line_item ) {
			if ( ! is_array( $line_item ) ) {
				return null;
			}

			$product_code = isset( $line_item['product_code'] ) && is_scalar( $line_item['product_code'] ) ? sanitize_text_field( trim( (string) $line_item['product_code'] ) ) : '';
			$quantity     = $line_item['quantity'] ?? null;

			$quantity_valid = is_int( $quantity ) || ( is_string( $quantity ) && preg_match( '/^\d+$/', trim( $quantity ) ) );
			if ( '' === $product_code || ! $quantity_valid || (int) $quantity <= 0 ) {
				return null;
			}

			$lines[] = array(
				'product_code' => $product_code,
				'quantity'     => (int) $quantity,
			);
		}

		return $lines;
	}

	/**
	 * The customer descriptor for the throwaway cart.
	 *
	 * @param array $payload Request body.
	 * @return array|WP_Error 400 for a malformed shipping method.
	 */
	private function build_customer( array $payload ) {
		$email = isset( $payload['email'] ) && is_string( $payload['email'] ) ? sanitize_email( trim( $payload['email'] ) ) : '';

		$shipping_method = null;
		if ( isset( $payload['shipping_method'] ) && '' !== $payload['shipping_method'] ) {
			$method = is_string( $payload['shipping_method'] ) ? trim( $payload['shipping_method'] ) : '';
			if ( ! preg_match( '/^[a-z0-9_-]+:\d+$/i', $method ) ) {
				return new WP_Error(
					'coupon.shipping_method_invalid',
					esc_html__( 'Coupon shipping_method must be a shipping rate id such as flat_rate:3.', 'miguel' ),
					array( 'status' => 400 )
				);
			}
			$shipping_method = $method;
		}

		// Per-customer usage limits are kept by user ID, which a guest would not have.
		$user = '' !== $email ? get_user_by( 'email', $email ) : false;

		return array(
			'email'           => $email,
			'user_id'         => $user ? (int) $user->ID : 0,
			'billing'         => $this->get_address( $payload['billing'] ?? null ),
			'shipping'        => $this->get_address( $payload['shipping'] ?? null ),
			'shipping_method' => $shipping_method,
		);
	}

	/**
	 * The country, state, city and postcode strings of an address object; anything else is ignored.
	 *
	 * @param mixed $address Value of billing or shipping in the request.
	 * @return array|null Null when no usable field was sent.
	 */
	private function get_address( $address ) {
		if ( ! is_array( $address ) ) {
			return null;
		}

		$clean = array();
		foreach ( array( 'country', 'state', 'city', 'postcode' ) as $field ) {
			if ( isset( $address[ $field ] ) && is_string( $address[ $field ] ) ) {
				$clean[ $field ] = sanitize_text_field( $address[ $field ] );
			}
		}

		return empty( $clean ) ? null : $clean;
	}

	/**
	 * Restrictions the cart could not judge for lack of input, so a yes is conditional.
	 *
	 * @param WC_Coupon $coupon   The coupon.
	 * @param array     $customer Customer descriptor.
	 * @return string[]
	 */
	private function unchecked_restrictions( $coupon, array $customer ) {
		return array();
	}
}
