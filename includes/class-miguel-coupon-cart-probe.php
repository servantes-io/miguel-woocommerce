<?php
/**
 * Prices a list of products in a throwaway WooCommerce cart, with or without a coupon.
 *
 * @package Miguel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Takes one snapshot of a cart priced by WooCommerce (and by whatever coupon plugins add on top).
 */
class Miguel_Coupon_Cart_Probe {

	/**
	 * Price the lines in a fresh cart, applying the coupon when one is given.
	 *
	 * @param array       $lines       List of array( 'product_id' => int, 'quantity' => int ).
	 * @param string|null $coupon_code Coupon code, or null to price the lines without a coupon.
	 * @param array       $customer    Customer descriptor, see Miguel_Isolated_Cart::run().
	 * @return array See the keys lines, extra, coupons, error_code, message, totals and notices.
	 * @throws Throwable Whatever the cart throws, after everything is restored.
	 */
	public static function price( array $lines, $coupon_code, array $customer ) {
		$ran = Miguel_Isolated_Cart::run(
			$customer,
			function ( $cart ) use ( $lines, $coupon_code, $customer ) {
				return self::snapshot( $cart, $lines, $coupon_code, $customer );
			}
		);

		$snapshot            = $ran['result'];
		$snapshot['notices'] = $ran['notices'];

		return $snapshot;
	}

	/**
	 * Fill the cart, apply the coupon, calculate and read the result.
	 *
	 * @param WC_Cart     $cart        The throwaway cart.
	 * @param array       $lines       Lines, see price().
	 * @param string|null $coupon_code Coupon code or null.
	 * @param array       $customer    Customer descriptor.
	 * @return array Snapshot without notices.
	 */
	private static function snapshot( $cart, array $lines, $coupon_code, array $customer ) {
		$keys = array();
		foreach ( array_values( $lines ) as $index => $line ) {
			$key            = $cart->add_to_cart( (int) $line['product_id'], (int) $line['quantity'] );
			$keys[ $index ] = false === $key ? null : (string) $key;
		}

		$error_code = null;
		$message    = null;
		if ( null !== $coupon_code && '' !== $coupon_code ) {
			// First error about this coupon (or about no coupon in particular), first about another one.
			$own       = null;
			$other     = null;
			$requested = wc_strtolower( wc_format_coupon_code( (string) $coupon_code ) );
			$capture   = function ( $text, $code, $coupon = null ) use ( $requested, &$own, &$other ) {
				$error = array( (int) $code, html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
				if ( ! $coupon instanceof WC_Coupon || wc_strtolower( wc_format_coupon_code( $coupon->get_code() ) ) === $requested ) {
					$own = null === $own ? $error : $own;
				} else {
					$other = null === $other ? $error : $other;
				}
				return $text;
			};

			add_filter( 'woocommerce_coupon_error', $capture, 10, 3 );
			try {
				$applied = $cart->apply_coupon( (string) $coupon_code );

				// An individual-use coupon already on the cart refuses this one with an error about itself.
				if ( ! $applied && null === $own ) {
					$own = $other;
				}

				// The web checkout judges allowed e-mails and a guest's per-customer limit here, for every
				// applied coupon. WooCommerce removes the coupon for the former but only reports the latter
				// (the order is refused later). Another coupon's refusal is not this coupon's.
				$email = (string) $customer['email'];
				if ( $applied && '' !== $email ) {
					$cart->check_customer_coupons( array( 'billing_email' => $email ) );
					if ( null !== $own ) {
						$cart->remove_coupon( (string) $coupon_code );
					}
				}
			} finally {
				remove_filter( 'woocommerce_coupon_error', $capture, 10 );
			}

			if ( null !== $own ) {
				list( $error_code, $message ) = $own;
			}
		}

		if ( ! empty( $customer['shipping_method'] ) ) {
			$cart->calculate_shipping();
		}
		$cart->calculate_totals();

		$contents   = $cart->get_cart();
		$line_cells = array();
		$used_keys  = array();
		foreach ( array_values( $lines ) as $index => $line ) {
			$key = $keys[ $index ];
			if ( null === $key || ! isset( $contents[ $key ] ) ) {
				$line_cells[ $index ] = array(
					'in_cart_quantity' => 0,
					'unit_price'       => 0.0,
					'total'            => 0.0,
					'tax'              => 0.0,
				);
				continue;
			}

			$used_keys[ $key ] = true;
			$item              = $contents[ $key ];
			$item_quantity     = max( 1, (int) $item['quantity'] );
			$unit_price        = ( (float) $item['line_total'] + (float) $item['line_tax'] ) / $item_quantity;
			$in_cart           = min( (int) $line['quantity'], $item_quantity );

			$line_cells[ $index ] = array(
				'in_cart_quantity' => $in_cart,
				'unit_price'       => self::money( $unit_price ),
				'total'            => self::money( $unit_price * $in_cart ),
				'tax'              => self::money( (float) $item['line_tax'] / $item_quantity * $in_cart ),
			);
		}

		$extra = array();
		foreach ( $contents as $key => $item ) {
			if ( isset( $used_keys[ $key ] ) ) {
				continue;
			}
			$quantity = max( 1, (int) $item['quantity'] );
			$total    = (float) $item['line_total'] + (float) $item['line_tax'];
			$extra[]  = array(
				'key'          => (string) $key,
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'quantity'     => (int) $item['quantity'],
				'unit_price'   => self::money( $total / $quantity ),
				'total'        => self::money( $total ),
				'tax'          => self::money( (float) $item['line_tax'] ),
				'source'       => isset( $item['wc_sc_product_source'] ) ? (string) $item['wc_sc_product_source'] : null,
			);
		}

		return array(
			'lines'      => $line_cells,
			'extra'      => $extra,
			'coupons'    => array_values( $cart->get_applied_coupons() ),
			'error_code' => $error_code,
			'message'    => $message,
			'totals'     => array(
				'items'    => self::money( (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax() ),
				'discount' => self::money( (float) $cart->get_discount_total() + (float) $cart->get_discount_tax() ),
				'shipping' => empty( $customer['shipping_method'] ) ? null : self::money( (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() ),
				'fees'     => self::money( (float) $cart->get_fee_total() + (float) $cart->get_fee_tax() ),
				'total'    => self::money( (float) $cart->get_total( 'edit' ) ),
				'tax'      => self::money( (float) $cart->get_total_tax() ),
			),
		);
	}

	/**
	 * Round a computed amount to the shop's price decimals (WooCommerce keeps line totals unrounded
	 * internally, so tax-inclusive sums land a fraction off).
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	private static function money( $amount ) {
		return round( (float) $amount, wc_get_price_decimals() );
	}
}
