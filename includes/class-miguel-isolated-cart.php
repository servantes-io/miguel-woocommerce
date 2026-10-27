<?php
/**
 * Runs a callback against a throwaway WooCommerce cart.
 *
 * @package Miguel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Swaps WC()->cart, WC()->session, WC()->customer and the current user for fresh,
 * unsaved ones for the duration of one callback, then restores the originals.
 */
class Miguel_Isolated_Cart {

	/**
	 * Run $work with a fresh cart for the described customer.
	 *
	 * @param array    $customer Keys: email, user_id (0 = guest), billing, shipping (array|null with
	 *                           country, state, city, postcode), shipping_method (string|null).
	 * @param callable $work     Receives the WC_Cart.
	 * @return array array( 'result' => mixed, 'notices' => string[] ).
	 * @throws Throwable Whatever $work throws, after everything is restored.
	 */
	public static function run( array $customer, callable $work ) {
		$wc = WC();

		$saved_cart     = $wc->cart;
		$saved_session  = $wc->session;
		$saved_customer = $wc->customer;
		$saved_user_id  = get_current_user_id();

		try {
			wp_set_current_user( (int) $customer['user_id'] );

			$session = new Miguel_Memory_Session();
			$session->set( 'chosen_payment_method', 'miguel' );
			if ( ! empty( $customer['shipping_method'] ) ) {
				$session->set( 'chosen_shipping_methods', array( $customer['shipping_method'] ) );
			}
			$wc->session = $session;

			$wc->customer = self::build_customer( $customer );
			$wc->cart     = new WC_Cart();

			$result = call_user_func( $work, $wc->cart );

			return array(
				'result'  => $result,
				'notices' => self::collect_notices( $session ),
			);
		} finally {
			$wc->cart     = $saved_cart;
			$wc->session  = $saved_session;
			$wc->customer = $saved_customer;
			wp_set_current_user( $saved_user_id );
		}
	}

	/**
	 * An unsaved customer carrying the email and addresses.
	 *
	 * @param array $customer Customer descriptor.
	 * @return WC_Customer
	 */
	private static function build_customer( array $customer ) {
		$wc_customer = new WC_Customer( 0 );
		$wc_customer->set_billing_email( (string) $customer['email'] );

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( empty( $customer[ $type ] ) || ! is_array( $customer[ $type ] ) ) {
				continue;
			}
			foreach ( array( 'country', 'state', 'city', 'postcode' ) as $field ) {
				if ( isset( $customer[ $type ][ $field ] ) ) {
					$wc_customer->{ "set_{$type}_{$field}" }( (string) $customer[ $type ][ $field ] );
				}
			}
		}

		return $wc_customer;
	}

	/**
	 * Plain-text, de-duplicated messages of every notice type in the session.
	 *
	 * @param WC_Session $session The throwaway session.
	 * @return string[]
	 */
	private static function collect_notices( $session ) {
		$messages = array();

		foreach ( (array) $session->get( 'wc_notices', array() ) as $notices ) {
			foreach ( (array) $notices as $notice ) {
				$text = is_array( $notice ) ? ( isset( $notice['notice'] ) ? $notice['notice'] : '' ) : $notice;
				$text = trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $text ) {
					$messages[] = $text;
				}
			}
		}

		return array_values( array_unique( $messages ) );
	}
}
