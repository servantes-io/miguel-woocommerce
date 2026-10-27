<?php
/**
 * Stand-ins for Smart Coupons and Melvil's pricing plugin in the coupon preview tests.
 *
 * Each mimics how the real plugin hooks into WooCommerce (hook names, priorities, cart item data
 * keys, coupon meta keys, exception codes) and the effect a test can observe — never its coupon
 * rules. The licensed plugins are not in the repository. Required by the tests that use them, not
 * by tests/bootstrap.php; each mock's install() adds its hooks and uninstall() removes them.
 *
 * @package Miguel\Tests
 */

/**
 * Smart Coupons' "add products" action (WC_SC_Coupon_Actions): a coupon with gift products adds
 * them to WC()->cart when it is applied, each as its own line marked with the coupon code, unless
 * the customer has to pick them; and every add-to-cart resets an item list kept in the session.
 */
class Miguel_Mock_Sc_Gift {

	/**
	 * Add the hooks.
	 */
	public static function install() {
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'coupon_action' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'reset_session' ), 99 );
	}

	/**
	 * Remove the hooks.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_applied_coupon', array( __CLASS__, 'coupon_action' ) );
		remove_action( 'woocommerce_add_to_cart', array( __CLASS__, 'reset_session' ), 99 );
	}

	/**
	 * Add the coupon's gift products to the global cart.
	 *
	 * @param string $coupon_code Applied coupon code.
	 */
	public static function coupon_action( $coupon_code ) {
		$coupon  = new WC_Coupon( $coupon_code );
		$actions = $coupon->get_meta( 'wc_sc_add_product_details' );
		if ( empty( $actions ) || ! is_array( $actions ) || 'yes' === $coupon->get_meta( 'wc_sc_no_of_selectable_product' ) ) {
			return;
		}

		$added = false;
		foreach ( $actions as $action ) {
			if ( empty( $action['product_id'] ) ) {
				continue;
			}
			$key   = WC()->cart->add_to_cart( absint( $action['product_id'] ), absint( $action['quantity'] ), 0, array(), array( 'wc_sc_product_source' => $coupon_code ) );
			$added = $added || ! empty( $key );
		}

		if ( $added ) {
			wc_add_notice( 'Gift added', 'success' );
		}
	}

	/**
	 * Smart Coupons' cheapest/costliest item list is dropped from the session on every add-to-cart.
	 */
	public static function reset_session() {
		WC()->session->__unset( 'wc_sc_cheapest_costliest_items_session' );
	}
}

if ( ! class_exists( 'WC_SC_Coupons_By_Location', false ) ) {
	/**
	 * Smart Coupons' location restriction, under the real class name so the route can find it: the
	 * validator throws (code 0) when the customer's billing or shipping country is not one of the
	 * coupon's locations — an empty address included — and lets it through when there is no
	 * customer object at all.
	 */
	class WC_SC_Coupons_By_Location {

		/**
		 * Singleton instance.
		 *
		 * @var WC_SC_Coupons_By_Location|null
		 */
		private static $instance = null;

		/**
		 * The singleton, as Smart Coupons exposes it.
		 *
		 * @return WC_SC_Coupons_By_Location
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Add the validator at Smart Coupons' priority.
		 */
		public static function install() {
			add_filter( 'woocommerce_coupon_is_valid', array( self::get_instance(), 'validate' ), 11, 3 );
		}

		/**
		 * Remove the validator.
		 */
		public static function uninstall() {
			remove_filter( 'woocommerce_coupon_is_valid', array( self::get_instance(), 'validate' ), 11 );
		}

		/**
		 * Refuse a coupon whose location rule the customer's address does not meet.
		 *
		 * @param bool              $valid     Valid so far.
		 * @param WC_Coupon         $coupon    The coupon.
		 * @param WC_Discounts|null $discounts The discounts object.
		 * @return bool
		 * @throws Exception When the address does not match.
		 */
		public function validate( $valid, $coupon, $discounts = null ) {
			if ( ! $valid ) {
				return $valid;
			}

			$lookup_in = $coupon->get_meta( 'sa_cbl_locations_lookup_in' );
			if ( empty( $lookup_in['address'] ) ) {
				return $valid;
			}

			$address   = $lookup_in['address'];
			$locations = $coupon->get_meta( 'sa_cbl_' . $address . '_locations' );
			$customer  = WC()->customer;
			if ( empty( $locations['additional_locations'] ) || ! is_object( $customer ) ) {
				return $valid;
			}

			$country = 'billing' === $address ? $customer->get_billing_country() : $customer->get_shipping_country();
			if ( in_array( $country, (array) $locations['additional_locations'], true ) ) {
				return true;
			}

			throw new Exception( 'Coupon is not valid for the ' . ( 'billing' === $address ? 'billing address' : 'shipping address' ) );
		}
	}
}

/**
 * Smart Coupons' payment method restriction (WC_SC_Coupons_By_Payment_Method): the validator reads
 * the session's chosen payment method and throws (code 0) when it is not one of the coupon's.
 */
class Miguel_Mock_Sc_Payment_Method {

	/**
	 * The refusal message.
	 */
	const MESSAGE = 'This coupon is not valid for the selected payment method.';

	/**
	 * Add the validator at Smart Coupons' priority.
	 */
	public static function install() {
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate' ), 11, 3 );
	}

	/**
	 * Remove the validator.
	 */
	public static function uninstall() {
		remove_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate' ), 11 );
	}

	/**
	 * Refuse a coupon restricted to other payment methods.
	 *
	 * @param bool         $valid     Valid so far.
	 * @param WC_Coupon    $coupon    The coupon.
	 * @param WC_Discounts $discounts The discounts object.
	 * @return bool
	 * @throws Exception When the chosen payment method is not allowed.
	 */
	public static function validate( $valid, $coupon, $discounts = null ) {
		$method_ids = $coupon->get_meta( 'wc_sc_payment_method_ids' );
		if ( ! $valid || empty( $method_ids ) || ! is_array( $method_ids ) ) {
			return $valid;
		}

		$chosen = WC()->session->__isset( 'chosen_payment_method' ) ? WC()->session->get( 'chosen_payment_method' ) : '';
		if ( '' === (string) $chosen || ! in_array( (string) $chosen, $method_ids, true ) ) {
			throw new Exception( self::MESSAGE );
		}

		return $valid;
	}
}

/**
 * A Smart Coupons rule judged again after the totals: a coupon with meta `mock_remove_on_totals`
 * is taken off WC()->cart on woocommerce_after_calculate_totals, with an error notice.
 */
class Miguel_Mock_Sc_Revalidate {

	/**
	 * Add the hook.
	 */
	public static function install() {
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'revalidate' ) );
	}

	/**
	 * Remove the hook.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'revalidate' ) );
	}

	/**
	 * Drop the coupons the rule refuses.
	 */
	public static function revalidate() {
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( ( new WC_Coupon( $code ) )->get_meta( 'mock_remove_on_totals' ) ) {
				WC()->cart->remove_coupon( $code );
				wc_add_notice( 'Coupon removed by rule', 'error' );
			}
		}
	}
}

/**
 * Smart Coupons' store credit applied after tax (WC_Smart_Coupons): coupon type `smart_coupon`,
 * no line discount, the credit taken off the cart total and added to the discount total on
 * woocommerce_after_calculate_totals, with what was used kept on the cart object.
 */
class Miguel_Mock_Sc_Store_Credit {

	/**
	 * Add the hooks.
	 */
	public static function install() {
		add_filter( 'woocommerce_coupon_discount_types', array( __CLASS__, 'add_discount_type' ) );
		add_filter( 'woocommerce_cart_coupon_types', array( __CLASS__, 'add_cart_coupon_type' ) );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_calculate_totals' ), 9999 );
	}

	/**
	 * Remove the hooks.
	 */
	public static function uninstall() {
		remove_filter( 'woocommerce_coupon_discount_types', array( __CLASS__, 'add_discount_type' ) );
		remove_filter( 'woocommerce_cart_coupon_types', array( __CLASS__, 'add_cart_coupon_type' ) );
		remove_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_calculate_totals' ), 9999 );
	}

	/**
	 * Register the store credit discount type.
	 *
	 * @param array $types Discount types.
	 * @return array
	 */
	public static function add_discount_type( $types ) {
		$types['smart_coupon'] = 'Store Credit / Gift Certificate';

		return $types;
	}

	/**
	 * Store credit is a cart coupon.
	 *
	 * @param array $types Cart coupon types.
	 * @return array
	 */
	public static function add_cart_coupon_type( $types ) {
		$types[] = 'smart_coupon';

		return $types;
	}

	/**
	 * Take the credit off the total, once per calculation.
	 *
	 * WooCommerce calculates a cart several times (on add-to-cart, on apply, on demand) and resets
	 * its totals each time, so the credit is taken off every calculation; like Smart Coupons, the
	 * mock counts `woocommerce_cart_reset` to act only once in each and keeps the credit used on
	 * the cart object.
	 *
	 * @param WC_Cart $cart The cart.
	 */
	public static function after_calculate_totals( $cart ) {
		$calculation = did_action( 'woocommerce_cart_reset' );
		if ( isset( $cart->miguel_mock_credit_calculation ) && $calculation === $cart->miguel_mock_credit_calculation ) {
			return;
		}
		$cart->miguel_mock_credit_calculation = $calculation;

		$left = (float) $cart->get_total( 'edit' );
		$used = array();
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			if ( $coupon->is_type( 'smart_coupon' ) ) {
				$used[ $code ] = min( (float) $coupon->get_amount(), $left );
				$left         -= $used[ $code ];
			}
		}
		$cart->miguel_mock_credit_used = $used;
		if ( ! $used ) {
			return;
		}

		$cart->set_discount_total( (float) $cart->get_discount_total() + array_sum( $used ) );
		$cart->set_total( $left );
	}
}

/**
 * Smart Coupons' auto-apply (WC_SC_Auto_Apply_Coupon): coupon `auto5` is applied to WC()->cart on
 * every add-to-cart when it is not on it yet.
 */
class Miguel_Mock_Sc_Auto_Apply {

	/**
	 * The auto-applied code.
	 */
	const CODE = 'auto5';

	/**
	 * Add the hook.
	 */
	public static function install() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'auto_apply_coupons' ) );
	}

	/**
	 * Remove the hook.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_add_to_cart', array( __CLASS__, 'auto_apply_coupons' ) );
	}

	/**
	 * Apply the coupon.
	 */
	public static function auto_apply_coupons() {
		if ( ! WC()->cart->has_discount( self::CODE ) ) {
			WC()->cart->apply_coupon( self::CODE );
		}
	}
}

/**
 * Melvil's pricing (WcMelvilCart / WcMelvilPrices): the coupon's discount goes into the line prices
 * on woocommerce_before_calculate_totals (priority 20, on the cart it is handed) and WooCommerce's
 * own discount for the coupon is forced to 0. Here: 20 % off every line while `c20` is applied.
 */
class Miguel_Mock_Melvil_Pricing {

	/**
	 * Add the hooks.
	 */
	public static function install() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 20 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'discount_amount' ), 10, 5 );
	}

	/**
	 * Remove the hooks.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 20 );
		remove_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'discount_amount' ), 10 );
	}

	/**
	 * Price the lines.
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 */
	public static function before_calculate_totals( $cart ) {
		$factor = in_array( 'c20', $cart->get_applied_coupons(), true ) ? 0.8 : 1.0;
		foreach ( $cart->get_cart() as $item ) {
			$item['data']->set_price( (float) $item['data']->get_regular_price() * $factor );
		}
	}

	/**
	 * WooCommerce's coupon discount is always 0: the discount is in the line price.
	 *
	 * @return float
	 */
	public static function discount_amount() {
		return 0.0;
	}
}
