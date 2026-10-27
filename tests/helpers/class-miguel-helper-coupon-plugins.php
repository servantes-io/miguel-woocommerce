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
 * them to WC()->cart when it is applied, each as its own line marked with the coupon code and
 * priced at the gift's own discount, unless the customer has to pick them; and every add-to-cart
 * resets an item list kept in the session.
 */
class Miguel_Mock_Sc_Gift {

	/**
	 * Add the hooks.
	 */
	public static function install() {
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'coupon_action' ) );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'price_gift' ), 15 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'reset_session' ), 99 );
	}

	/**
	 * Remove the hooks.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_applied_coupon', array( __CLASS__, 'coupon_action' ) );
		remove_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'price_gift' ), 15 );
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

		$names = array();
		foreach ( $actions as $action ) {
			if ( empty( $action['product_id'] ) ) {
				continue;
			}
			$key = WC()->cart->add_to_cart( absint( $action['product_id'] ), absint( $action['quantity'] ), 0, array(), array( 'wc_sc_product_source' => $coupon_code ) );
			if ( ! empty( $key ) ) {
				$names[] = wc_get_product( absint( $action['product_id'] ) )->get_name();
			}
		}

		if ( $names ) {
			wc_add_notice( sprintf( '%s has been added to your cart!', implode( ', ', $names ) ), 'success' );
		}
	}

	/**
	 * Price a gift line at its discount: `percent` of the price or a `flat` amount off, never below 0.
	 *
	 * @param array $cart_item Cart item being added.
	 * @return array
	 */
	public static function price_gift( $cart_item ) {
		if ( empty( $cart_item['wc_sc_product_source'] ) ) {
			return $cart_item;
		}

		$coupon = new WC_Coupon( $cart_item['wc_sc_product_source'] );
		foreach ( (array) $coupon->get_meta( 'wc_sc_add_product_details' ) as $action ) {
			if ( empty( $action['product_id'] ) || absint( $action['product_id'] ) !== (int) $cart_item['product_id'] || ! isset( $action['discount_amount'] ) || '' === $action['discount_amount'] ) {
				continue;
			}

			$price    = (float) $cart_item['data']->get_price();
			$discount = 'flat' === ( $action['discount_type'] ?? 'percent' ) ? (float) $action['discount_amount'] : $price * (float) $action['discount_amount'] / 100;
			$cart_item['data']->set_price( $price - min( $price, $discount ) );
			break;
		}

		return $cart_item;
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
 * the session's chosen payment method and throws (code 0) when it is not one of the coupon's, with
 * a message in HTML that names the code and the allowed gateways' titles.
 */
class Miguel_Mock_Sc_Payment_Method {

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
			$gateways = WC()->payment_gateways()->payment_gateways();
			$titles   = array();
			foreach ( $method_ids as $method_id ) {
				$titles[] = isset( $gateways[ $method_id ] ) ? $gateways[ $method_id ]->get_title() : $method_id;
			}

			throw new Exception(
				sprintf(
					'Coupon code %1$s is valid only for %2$s: %3$s. You can change payment method from the %4$s page.',
					'<code>' . esc_html( $coupon->get_code() ) . '</code>',
					1 === count( $titles ) ? 'payment method' : 'payment methods',
					'<strong>"' . esc_html( implode( '", "', $titles ) ) . '"</strong>',
					'<a href="' . esc_url( wc_get_checkout_url() ) . '"><strong>Checkout</strong></a>'
				)
			);
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
 * no line discount, the credit taken off the cart total and the items total and added to the
 * discount total on woocommerce_after_calculate_totals, with what was used kept on the cart object.
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
		$cart->set_cart_contents_total( max( 0, (float) $cart->get_cart_contents_total() - array_sum( $used ) ) );
		$cart->set_total( $left );
	}
}

/**
 * Smart Coupons' auto-apply (WC_SC_Auto_Apply_Coupon) with store credit applied after tax: coupon
 * `auto5` is applied to WC()->cart, which is then calculated again, whenever the cart has an items
 * total: on add-to-cart (where a first line has none yet, as WooCommerce calculates it later in the
 * same action) and after every calculation with a total (WC_Smart_Coupons fires its
 * `smart_coupons_before_calculate_totals` there).
 */
class Miguel_Mock_Sc_Auto_Apply {

	/**
	 * The auto-applied code.
	 */
	const CODE = 'auto5';

	/**
	 * Whether an auto-apply is running (the real plugin's guard against re-entry).
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * Add the hooks.
	 */
	public static function install() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'auto_apply_coupons' ) );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_calculate_totals' ), 9999 );
	}

	/**
	 * Remove the hooks.
	 */
	public static function uninstall() {
		remove_action( 'woocommerce_add_to_cart', array( __CLASS__, 'auto_apply_coupons' ) );
		remove_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_calculate_totals' ), 9999 );
	}

	/**
	 * Auto-apply after a calculation that has a total.
	 *
	 * @param WC_Cart $cart The cart.
	 */
	public static function after_calculate_totals( $cart ) {
		if ( ! empty( $cart->get_total( 'edit' ) ) ) {
			self::auto_apply_coupons();
		}
	}

	/**
	 * Apply the coupon to a cart that has an items total.
	 */
	public static function auto_apply_coupons() {
		$cart = WC()->cart;
		if ( self::$applying || $cart->is_empty() || (float) $cart->get_cart_contents_total() <= 0 || $cart->has_discount( self::CODE ) ) {
			return;
		}

		self::$applying = true;
		try {
			$cart->apply_coupon( self::CODE );
			$cart->calculate_totals();
		} finally {
			self::$applying = false;
		}
	}
}

/**
 * Melvil's pricing (WcMelvilCart / WcMelvilPrices) for a printed book: on
 * woocommerce_before_calculate_totals (priority 20, on the cart it is handed) every line is priced
 * at the shop's 15 % off its regular price, or, while a percent coupon is applied, at the coupon's
 * percentage off the regular price instead (the two never add up), rounded to whole units; and
 * WooCommerce's own discount for any coupon is forced to 0 (WcMelvilCart::disable_coupon_on_bundles
 * returns 0 for every item, after melvil_coupon_get_discount_amount at the same priority).
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
		$percent_off = 15.0;
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( $coupon->is_type( 'percent' ) ) {
				$percent_off = (float) $coupon->get_amount();
				break;
			}
		}

		foreach ( $cart->get_cart() as $item ) {
			$regular = (float) $item['data']->get_regular_price();
			$item['data']->set_price( round( $regular - $regular * $percent_off / 100 ) );
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
