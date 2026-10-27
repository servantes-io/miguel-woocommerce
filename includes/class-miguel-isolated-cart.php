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

		// The original cart and its cart session must not react to what happens in the throwaway cart.
		$detached = self::detach_hooks( self::cart_objects( $saved_cart ) );
		$throwaway = array();

		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false', PHP_INT_MAX );

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
			$throwaway    = self::cart_objects( $wc->cart );

			// Keep the cart session's set_session callbacks, but never persist or set cookies.
			foreach ( $throwaway as $object ) {
				if ( $object instanceof WC_Cart_Session ) {
					remove_action( 'woocommerce_add_to_cart', array( $object, 'persistent_cart_update' ) );
					remove_action( 'woocommerce_cart_item_removed', array( $object, 'persistent_cart_update' ) );
					remove_action( 'woocommerce_cart_item_restored', array( $object, 'persistent_cart_update' ) );
					remove_action( 'woocommerce_cart_item_set_quantity', array( $object, 'persistent_cart_update' ) );
					remove_action( 'woocommerce_add_to_cart', array( $object, 'maybe_set_cart_cookies' ) );
					remove_action( 'wp', array( $object, 'maybe_set_cart_cookies' ), 99 );
					remove_action( 'shutdown', array( $object, 'maybe_set_cart_cookies' ), 0 );
				}
			}

			$result = call_user_func( $work, $wc->cart );

			return array(
				'result'  => $result,
				'notices' => self::collect_notices( $session ),
			);
		} finally {
			remove_filter( 'woocommerce_persistent_cart_enabled', '__return_false', PHP_INT_MAX );
			self::detach_hooks( $throwaway );
			self::reattach_hooks( $detached );

			$wc->cart     = $saved_cart;
			$wc->session  = $saved_session;
			$wc->customer = $saved_customer;
			wp_set_current_user( $saved_user_id );
		}
	}

	/**
	 * A cart and its WC_Cart_Session (the objects whose methods WooCommerce hooks).
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return object[]
	 */
	private static function cart_objects( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return array();
		}

		$read_session = function () {
			return $this->session;
		};
		$session      = Closure::bind( $read_session, $cart, WC_Cart::class )();

		return is_object( $session ) ? array( $cart, $session ) : array( $cart );
	}

	/**
	 * Remove every hook callback that is a method of one of the objects.
	 *
	 * @param object[] $objects Objects.
	 * @return array Removed callbacks, for reattach_hooks(): hook name => array( 'hook' => WP_Hook,
	 *               'order' => priority => ids of the whole bucket, 'buckets' => priority => id => entry ).
	 */
	private static function detach_hooks( array $objects ) {
		global $wp_filter;

		$removed = array();
		if ( ! $objects ) {
			return $removed;
		}

		foreach ( $wp_filter as $name => $hook ) {
			foreach ( $hook->callbacks as $priority => $entries ) {
				foreach ( $entries as $id => $entry ) {
					$function = $entry['function'];
					if ( is_array( $function ) && is_object( $function[0] ) && in_array( $function[0], $objects, true ) ) {
						$removed[ $name ]['hook']                        = $hook;
						$removed[ $name ]['order'][ $priority ]          = array_keys( $entries );
						$removed[ $name ]['buckets'][ $priority ][ $id ] = $entry;
					}
				}
			}
		}

		foreach ( $removed as $name => $info ) {
			foreach ( $info['buckets'] as $priority => $entries ) {
				foreach ( $entries as $entry ) {
					remove_filter( $name, $entry['function'], $priority );
				}
			}
		}

		return $removed;
	}

	/**
	 * Put detached callbacks back at their old position in their priority bucket.
	 *
	 * @param array $removed Result of detach_hooks().
	 */
	private static function reattach_hooks( array $removed ) {
		global $wp_filter;

		foreach ( $removed as $name => $info ) {
			if ( ! isset( $wp_filter[ $name ] ) ) {
				$wp_filter[ $name ] = $info['hook']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
			$hook = $wp_filter[ $name ];
			foreach ( $info['buckets'] as $priority => $entries ) {
				$current = isset( $hook->callbacks[ $priority ] ) ? $hook->callbacks[ $priority ] : array();
				$merged  = array();
				foreach ( $info['order'][ $priority ] as $id ) {
					if ( isset( $entries[ $id ] ) ) {
						$merged[ $id ] = $entries[ $id ];
					} elseif ( isset( $current[ $id ] ) ) {
						$merged[ $id ] = $current[ $id ];
					}
				}
				$hook->callbacks[ $priority ] = $merged + $current;
			}
			ksort( $hook->callbacks, SORT_NUMERIC );
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
