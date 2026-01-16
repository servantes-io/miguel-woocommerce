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
	 * @return string
	 */
	public static function get_user_id_for_order( $order ) {
		$user_id = $order->get_user_id();
		if ( $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			return $user ? strval( $user->ID ) : md5( self::get_email_for_order( $order ) );
		}

		return md5( self::get_email_for_order( $order ) );
	}

	/**
	 * Get email for order
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public static function get_email_for_order( $order ) {
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
			$country_name = WC()->countries->countries[ $order->get_billing_country() ];
			if ( ! $country_name ) {
				$country_name = $order->get_billing_country();
			}
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
	 * @return string|null
	 */
	public static function get_purchase_date_for_order( $order ) {
		$paid_date = $order->get_date_paid();
		if ( $paid_date ) {
			$paid_date->setTimezone( new DateTimeZone( 'UTC' ) );
			return $paid_date->format( 'Y-m-d\TH:i:s.u\Z' );
		}

		$created_date = $order->get_date_created();
		if ( $created_date ) {
			$created_date->setTimezone( new DateTimeZone( 'UTC' ) );
			return $created_date->format( 'Y-m-d\TH:i:s.u\Z' );
		}

		return null;
	}

	/**
	 * Get user data array for order
	 */
	public static function get_user_data_for_order_v1( $order, $enhanced_address = false ) {
		return array(
			'id' => self::get_user_id_for_order( $order ),
			'email' => self::get_email_for_order( $order ),
			'full_name' => self::get_full_name_for_order( $order ),
			'address' => self::get_address_for_order( $order, $enhanced_address ),
			'lang' => self::get_language_for_order( $order ),
		);
	}

	/**
	 * Get user data array for order
	 */
	public static function get_user_data_for_order_v2( $order, $enhanced_address = false ) {
		return array(
			'id' => self::get_user_id_for_order( $order ),
			'email' => self::get_email_for_order( $order ),
			'name' => self::get_full_name_for_order( $order ),
			'address' => self::get_address_for_order( $order, $enhanced_address ),
		);
	}

	/**
	 * Parses shortcode attributes for both Miguel and Wosa shortcodes
	 *
	 * @param string $shortcode Shortcode string.
	 * @return array|null Parsed attributes or null if not a valid shortcode.
	 */
	public static function parse_shortcode_atts( $shortcode ) {
		if ( miguel_starts_with( $shortcode, '[miguel ' ) ) {
			return miguel_get_shortcode_atts(
				$shortcode,
				array(
					'id' => '',
					'format' => '',
				)
			);
		} else if ( miguel_starts_with( $shortcode, '[wosa ' ) ) {
			$atts = miguel_get_shortcode_atts(
				$shortcode,
				array(
					'book' => '',
					'format' => '',
				)
			);
			$atts['id'] = $atts['book'];
			return $atts;
		}

		return null;
	}

	/**
	 * Check if file URL is a Miguel shortcode
	 *
	 * @param string $file_url File URL.
	 * @return bool
	 */
	public static function is_miguel_shortcode( $file_url ) {
		return miguel_starts_with( $file_url, '[miguel ' ) || miguel_starts_with( $file_url, '[wosa ' );
	}

	/**
	 * Extract Miguel code from shortcode
	 *
	 * @param string $shortcode Shortcode string.
	 * @return string|null
	 */
	public static function extract_miguel_code( $shortcode ) {
		// Use the comprehensive shortcode parser
		$atts = self::parse_shortcode_atts( $shortcode );

		// Return the id if available (works for both miguel and wosa shortcodes)
		if ( $atts && ! empty( $atts['id'] ) ) {
			return $atts['id'];
		}

		return null;
	}
}
