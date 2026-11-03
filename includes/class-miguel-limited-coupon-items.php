<?php
/**
 * Which items a coupon limited to X items goes to.
 *
 * @package Miguel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Melvil's pricing (woocommerce-melvil, WcMelvilPrices::process_cart on
 * woocommerce_before_calculate_totals, priority 20) gives a coupon limited to X items to the first
 * X units in cart order, so which book was free depended on what the customer added first. Just
 * before it, the cart is ordered cheapest first, or dearest first for a coupon Smart Coupons sets
 * to "Highest priced qualifying product in cart"; just after, the customer's order is put back.
 */
class Miguel_Limited_Coupon_Items {

	/**
	 * Smart Coupons' "Apply discount on" coupon meta, e.g. 1_cheapest_cart or 1_costliest_cart.
	 */
	const SC_SETTINGS_META = 'wc_sc_cheapest_costliest_settings';

	/**
	 * The cart keys in the customer's order, by spl_object_id of the cart being calculated.
	 *
	 * @var array<int, string[]>
	 */
	private static $keys = array();

	/**
	 * Register the hooks.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public static function register_hooks( Miguel_Hook_Manager_Interface $hook_manager ) {
		$hook_manager->add_action( 'woocommerce_before_calculate_totals', array( self::class, 'order_by_price' ), 19 );
		$hook_manager->add_action( 'woocommerce_before_calculate_totals', array( self::class, 'restore_order' ), 21 );
	}

	/**
	 * Order the cart by regular price, which Melvil discounts from, for a coupon limited to X items.
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 */
	public static function order_by_price( $cart ) {
		$direction = self::direction( $cart );
		if ( 0 === $direction ) {
			return;
		}

		$contents = $cart->get_cart_contents();
		$keys     = array_keys( $contents );
		// Sorting is stable since PHP 8, so equal prices keep the customer's order.
		uasort(
			$contents,
			fn( $a, $b ) => $direction * ( (float) $a['data']->get_regular_price() <=> (float) $b['data']->get_regular_price() )
		);

		self::$keys[ spl_object_id( $cart ) ] = $keys;
		$cart->set_cart_contents( $contents );
	}

	/**
	 * Put the cart back in the customer's order.
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 */
	public static function restore_order( $cart ) {
		$id = spl_object_id( $cart );
		if ( ! isset( self::$keys[ $id ] ) ) {
			return;
		}

		$contents = $cart->get_cart_contents();
		$cart->set_cart_contents( array_replace( array_intersect_key( array_flip( self::$keys[ $id ] ), $contents ), $contents ) );
		unset( self::$keys[ $id ] );
	}

	/**
	 * 1 for cheapest first, -1 for dearest first, 0 when no applied coupon is limited to X items.
	 * Melvil follows the first applied coupon with a limit, and so does this.
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 * @return int
	 */
	private static function direction( $cart ) {
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( $coupon->get_limit_usage_to_x_items() > 0 ) {
				return str_ends_with( (string) $coupon->get_meta( self::SC_SETTINGS_META ), '_costliest_cart' ) ? -1 : 1;
			}
		}

		return 0;
	}
}
