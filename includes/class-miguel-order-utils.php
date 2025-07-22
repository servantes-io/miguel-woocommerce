<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Order utilities class
 */
class Miguel_Order_Utils {

	/**
	 * Get user ID for order
	 */
	public static function get_user_id_for_order( $order ) {
		$user_id = $order->get_user_id();
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			return $user ? $user->ID : md5( self::get_email_for_order( $order ) );
		}

		return md5( self::get_email_for_order( $order ) );
	}

	/**
	 * Get email for order
	 */
	public static function get_email_for_order( $order ) {
		$user_id = $order->get_user_id();
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user ) {
				return $user->user_email;
			}
		}

		return $order->get_billing_email();
	}

	/**
	 * Get full name for order
	 */
	public static function get_full_name_for_order( $order ) {
		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	/**
	 * Get address for order
	 */
	public static function get_address_for_order( $order, $enhanced = false ) {
		if ( $enhanced ) {
			$country_name = WC()->countries->countries[ $order->get_billing_country() ] ?? $order->get_billing_country();
			return trim(
				$order->get_billing_address_1() . ' ' .
				$order->get_billing_city() . ' ' .
				$order->get_billing_postcode() . ' ' .
				$country_name
			);
		}

		return trim( $order->get_billing_address_1() . ' ' . $order->get_billing_city() );
	}

	/**
	 * Get language for order
	 */
	public static function get_language_for_order( $order ) {
		$user_id = $order->get_user_id();
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		return get_user_locale( $user );
	}

	/**
	 * Get purchase date for order
	 */
	public static function get_purchase_date_for_order( $order ) {
		$paid_date = $order->get_date_paid();
		if ( $paid_date ) {
			return $paid_date->format( DateTime::ATOM );
		}

		$created_date = $order->get_date_created();
		if ( $created_date ) {
			return $created_date->format( DateTime::ATOM );
		}

		return null;
	}

	/**
	 * Get user data array for order
	 */
	public static function get_user_data_for_order( $order, $enhanced_address = false ) {
		return array(
			'id' => self::get_user_id_for_order( $order ),
			'email' => self::get_email_for_order( $order ),
			'full_name' => self::get_full_name_for_order( $order ),
			'address' => self::get_address_for_order( $order, $enhanced_address ),
			'lang' => self::get_language_for_order( $order ),
		);
	}
}
