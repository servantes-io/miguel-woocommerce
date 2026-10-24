<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API that tells Miguel whether a WooCommerce coupon applies to a cart.
 *
 * Miguel keeps no coupon definitions: it asks here, with the cart it holds, and prices the
 * eligible lines itself. Nothing is saved.
 *
 * @package Miguel
 */
class Miguel_Coupon_Validate_Api {
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
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface     $hook_manager Hook manager.
	 * @param Miguel_Product_Code_Resolver|null $resolver Product code resolver.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, $resolver = null ) {
		$this->hook_manager = $hook_manager;
		$this->resolver     = $resolver instanceof Miguel_Product_Code_Resolver ? $resolver : new Miguel_Product_Code_Resolver();
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
			'/coupons/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_coupon' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Validate a coupon code against a cart.
	 *
	 * The cart becomes an unsaved order (billing e-mail, the customer with that e-mail, each line
	 * priced as sent), which WooCommerce's own coupon checks then judge.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_coupon( $request ) {
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

		$lines = $this->get_valid_lines( $payload['line_items'] ?? null );
		if ( null === $lines ) {
			return new WP_Error(
				'coupon.line_items_invalid',
				esc_html__( 'Coupon line_items must be a non-empty array of objects with product_code, a positive integer quantity and a non-negative price.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$email = isset( $payload['email'] ) && is_string( $payload['email'] ) ? sanitize_email( trim( $payload['email'] ) ) : '';
		$order = $this->build_unsaved_order( $email, $lines );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$coupon = new WC_Coupon( $code );
		$error  = $this->check_coupon( $order, $coupon );
		if ( null !== $error ) {
			return new WP_REST_Response( $error, 422 );
		}

		$limit = $coupon->get_limit_usage_to_x_items();

		return new WP_REST_Response(
			array(
				'code'                   => $coupon->get_code(),
				'discount_type'          => $coupon->get_discount_type(),
				'amount'                 => (float) $coupon->get_amount(),
				'limit_usage_to_x_items' => $limit ? (int) $limit : null,
				'eligible_product_codes' => $this->get_eligible_product_codes( $coupon, $order ),
			),
			200
		);
	}

	/**
	 * The request's lines, or null when any of them is malformed.
	 *
	 * @param mixed $line_items Value of line_items in the request.
	 * @return array|null Lines with a trimmed product_code, an int quantity and a float price.
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
			$price        = $line_item['price'] ?? null;

			$quantity_valid = is_int( $quantity ) || ( is_string( $quantity ) && preg_match( '/^\d+$/', trim( $quantity ) ) );
			if ( '' === $product_code || ! $quantity_valid || (int) $quantity <= 0 || ! is_numeric( $price ) || (float) $price < 0 ) {
				return null;
			}

			$lines[] = array(
				'product_code' => $product_code,
				'quantity'     => (int) $quantity,
				'price'        => (float) $price,
			);
		}

		return $lines;
	}

	/**
	 * An unsaved order holding the cart, for WooCommerce's coupon checks.
	 *
	 * @param string $email Customer e-mail.
	 * @param array  $lines Lines from get_valid_lines().
	 * @return WC_Order|WP_Error The resolver's error for a code it cannot resolve.
	 */
	private function build_unsaved_order( $email, $lines ) {
		$order = new WC_Order();
		$order->set_billing_email( $email );

		// Per-customer usage limits are kept by user ID, which a guest order would not have.
		$customer = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( $customer ) {
			$order->set_customer_id( (int) $customer->ID );
		}

		foreach ( $lines as $line ) {
			$resolved = $this->resolver->resolve_product_code( $line['product_code'] );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$item = new WC_Order_Item_Product();
			$item->set_product( wc_get_product( $resolved['product_id'] ) );
			$item->set_quantity( $line['quantity'] );
			$item->set_subtotal( $line['price'] * $line['quantity'] );
			$item->set_total( $line['price'] * $line['quantity'] );
			$item->update_meta_data( '_miguel_product_code', $line['product_code'] );
			$order->add_item( $item );
		}

		return $order;
	}

	/**
	 * Run WooCommerce's coupon checks on the order.
	 *
	 * WC_Discounts::is_coupon_valid() reports every failure as `invalid_coupon`; the reason's
	 * number (WC_Coupon::E_WC_COUPON_*) reaches only the woocommerce_coupon_error filter, so it is
	 * read there for this one call.
	 *
	 * The items are handed to WC_Discounts keyed by position: WC_Discounts::set_items_from_order()
	 * keys them by item ID, which is 0 for every line of an unsaved order, so all but the last
	 * line would be lost.
	 *
	 * @param WC_Order  $order  Unsaved order.
	 * @param WC_Coupon $coupon Coupon.
	 * @return array|null `{error_code, message}` when the coupon is refused, else null.
	 */
	private function check_coupon( $order, $coupon ) {
		$error_code = 0;
		$capture    = function ( $message, $code ) use ( &$error_code ) {
			$error_code = (int) $code;
			return $message;
		};

		$items = array();
		foreach ( array_values( $order->get_items() ) as $index => $order_item ) {
			$item           = new stdClass();
			$item->key      = $index;
			$item->object   = $order_item;
			$item->product  = $order_item->get_product();
			$item->quantity = $order_item->get_quantity();
			$item->price    = wc_add_number_precision_deep( $order_item->get_subtotal() );
			$items[ $index ] = $item;
		}

		$discounts = new WC_Discounts( $order );
		$discounts->set_items( $items );

		add_filter( 'woocommerce_coupon_error', $capture, 10, 2 );
		try {
			$result = $discounts->is_coupon_valid( $coupon );
		} finally {
			remove_filter( 'woocommerce_coupon_error', $capture, 10 );
		}

		if ( ! is_wp_error( $result ) ) {
			$error_code = $this->check_customer( $order, $coupon );
			if ( 0 === $error_code ) {
				return null;
			}

			$message = $coupon->get_coupon_error( $error_code );
		} else {
			$message = $result->get_error_message();
		}

		return array(
			'error_code' => $error_code,
			'message'    => html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' ),
		);
	}

	/**
	 * The checks the web checkout makes on the customer outside WC_Discounts.
	 *
	 * WC_Cart::check_customer_coupons() refuses a coupon whose allowed e-mails do not match the
	 * billing e-mail (WC_Discounts::is_coupon_valid() checks that itself only from WooCommerce
	 * 8.8) and holds a guest to the per-customer usage limit by billing e-mail (WC_Discounts
	 * counts registered customers only).
	 *
	 * @param WC_Order  $order  Unsaved order.
	 * @param WC_Coupon $coupon Coupon WC_Discounts found valid.
	 * @return int WC_Coupon::E_WC_COUPON_* error, or 0 when the coupon may be used.
	 */
	private function check_customer( $order, $coupon ) {
		$email        = strtolower( sanitize_email( $order->get_billing_email() ) );
		$restrictions = $coupon->get_email_restrictions();

		if ( is_array( $restrictions ) && ! empty( $restrictions ) && ! $this->is_email_allowed( $email, $restrictions ) ) {
			return WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED;
		}

		$limit      = $coupon->get_usage_limit_per_user();
		$data_store = $coupon->get_data_store();
		if ( 0 < $limit && 0 === $order->get_customer_id() && '' !== $email && $data_store && $data_store->get_usage_by_email( $coupon, $email ) >= $limit ) {
			return $data_store->get_tentative_usages_for_user( $coupon->get_id(), array( $email ) )
				? WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST
				: WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED;
		}

		return 0;
	}

	/**
	 * Whether an e-mail matches a coupon's allowed e-mails, where `*` stands for any text, as in
	 * WC_Cart::is_coupon_emails_allowed().
	 *
	 * @param string   $email        Lower-cased e-mail.
	 * @param string[] $restrictions Allowed e-mails.
	 * @return bool
	 */
	private function is_email_allowed( $email, $restrictions ) {
		if ( '' === $email ) {
			return false;
		}

		foreach ( $restrictions as $restriction ) {
			$pattern = '/^' . str_replace( '\\*', '(.+)?', preg_quote( (string) $restriction, '/' ) ) . '$/';
			if ( preg_match( $pattern, $email ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The product codes of the lines the coupon applies to, in request order, each once.
	 *
	 * WC_Coupon::is_valid_for_product() answers false for every cart-type coupon (fixed_cart), so
	 * such a coupon is asked on a copy typed as a product coupon: the same product and category
	 * include and exclude lists and exclude_sale_items, judged per line.
	 *
	 * @param WC_Coupon $coupon Coupon WooCommerce found valid.
	 * @param WC_Order  $order  Unsaved order from build_unsaved_order().
	 * @return string[]
	 */
	private function get_eligible_product_codes( $coupon, $order ) {
		$probe = $coupon;
		if ( ! $coupon->is_type( wc_get_product_coupon_types() ) ) {
			$probe = clone $coupon;
			$probe->set_discount_type( 'percent' );
		}

		$codes = array();
		foreach ( $order->get_items() as $item ) {
			if ( $probe->is_valid_for_product( $item->get_product(), $item ) ) {
				$codes[] = (string) $item->get_meta( '_miguel_product_code' );
			}
		}

		return array_values( array_unique( $codes ) );
	}
}
