<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * What refunds leave the customer: how many units of each line they are still entitled to.
 *
 * WooCommerce records a refund as a separate refund order and never changes the refunded order's
 * line items, so a line's quantity is always the quantity ordered. Everything that tells Miguel
 * what the customer owns asks this class instead.
 *
 * @package Miguel
 */
class Miguel_Order_Refunds {

	/**
	 * Units of a line the customer is still entitled to after the order's refunds.
	 *
	 * A unit is gone when its quantity was refunded, or when the money refunded on the line covers
	 * it — shops refunding digital goods often type only the amount. Whichever removes more wins.
	 * Money short of a whole unit (compensation) takes nothing away, and a free line can lose units
	 * only through its quantity.
	 *
	 * @param WC_Order              $order Order the line belongs to.
	 * @param WC_Order_Item_Product $item  Product line.
	 * @return int
	 */
	public static function get_entitled_quantity( $order, $item ) {
		$ordered = (int) $item->get_quantity();
		if ( $ordered <= 0 ) {
			return 0;
		}

		$item_id        = (int) $item->get_id();
		$refunded_qty   = (int) abs( $order->get_qty_refunded_for_item( $item_id ) );
		$refunded_total = abs( (float) $order->get_total_refunded_for_item( $item_id ) );

		// Rounded to the store's price precision, so several refunds each covering one rounded
		// unit price (33.33 against a 3 x 33.333... line) add up to the whole line instead of
		// falling short by floating-point error against the unrounded price. A line so cheap per
		// unit that rounding would make it look free (0) falls back to the unrounded price, since a
		// line that is not actually free must still be losable by money.
		$raw_unit_price = (float) $item->get_total() / $ordered;
		$unit_price     = round( $raw_unit_price, wc_get_price_decimals() );
		if ( 0.0 === $unit_price && $raw_unit_price > 0 ) {
			$unit_price = $raw_unit_price;
		}

		$money_units = 0;
		if ( $unit_price > 0 ) {
			// Half the smallest price step, so a refund of one unit's rounded price counts as that
			// unit despite floating-point division (33.33 of a 100.00 line of three).
			$tolerance   = 0.5 * pow( 10, -wc_get_price_decimals() );
			$money_units = (int) floor( ( $refunded_total + $tolerance ) / $unit_price );
		}

		return max( 0, $ordered - max( $refunded_qty, $money_units ) );
	}

	/**
	 * Whether refunds took away everything Miguel delivers in this order.
	 *
	 * True only when the order has at least one line whose product carries Miguel codes and every
	 * such line is left with no units. An order without Miguel lines has nothing to take away, so
	 * it is never "all refunded" by this measure.
	 *
	 * @param WC_Order $order            Order.
	 * @param callable $has_miguel_codes Receives a WC_Product; returns whether it carries Miguel codes.
	 * @return bool
	 */
	public static function has_refunded_all_miguel_items( $order, $has_miguel_codes ) {
		$has_miguel_line = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! call_user_func( $has_miguel_codes, $product ) ) {
				continue;
			}

			$has_miguel_line = true;
			if ( self::get_entitled_quantity( $order, $item ) > 0 ) {
				return false;
			}
		}

		return $has_miguel_line;
	}
}
