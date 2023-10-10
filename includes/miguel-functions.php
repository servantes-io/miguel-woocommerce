<?php
/**
 * Functions
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Starts_with
 *
 * @param string $haystack
 * @param string $needle
 *
 * @return boolean
 */
function miguel_starts_with( $haystack, $needle ) {
	return '' === $needle || strpos( $haystack, $needle ) === 0;
}

/**
 * Get_shortcode_atts
 *
 * @param string $shortcode
 * @param array  $defaults
 */
function miguel_get_shortcode_atts( $shortcode, $defaults = array() ) {
	return shortcode_atts( $defaults, shortcode_parse_atts( trim( $shortcode, '[]' ) ) );
}

/**
 * Compose path to template file
 *
 * @param string $tpl Template file name (without extension)
 *
 * @return string path
 */
function miguel_template_path( $tpl ) {
	return dirname( MIGUEL_PLUGIN_FILE ) . '/includes/views/' . $tpl . '.php';
}

/**
 *
 * Get_file
 *
 * @param int $product_id
 * @param int $download_id
 *
 * @return Miguel_File|WP_Error
 */
function miguel_get_file( $product_id, $download_id ) {
	try {
		$file = new Miguel_File( $product_id, $download_id );
	} catch ( \Exception $e ) {
		return new WP_Error( 'invalid-file', $e->getMessage() );
	}
	return $file;
}

/**
 * Get_file_download_url
 *
 * @param Miguel_File    $file
 * @param Miguel_Request $request
 */
function miguel_get_file_download_url( $file, $request ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"
		SELECT * FROM {$wpdb->prefix}woocommerce_miguel_async_requests
		WHERE order_id = %d AND product_id = %d AND download_id = %d
	",
			$request->get_order_id(),
			$file->get_product_id(),
			$file->get_download_id()
		)
	);
}

/**
 * Get_async_request
 *
 * @param int $guid
 * @return stdClass
 */
function miguel_get_async_request( $guid ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare(
			"
		SELECT * FROM {$wpdb->prefix}woocommerce_miguel_async_requests
		WHERE guid = %d
	",
			absint( $guid )
		)
	);
}

/**
 * Get_async_requests
 *
 * @param array $args
 *
 * @return int
 */
function miguel_insert_async_request( $args ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'status' => 'awaiting',
			'created' => current_time( 'mysql' ),
			'order_id' => '',
			'product_id' => '',
			'download_id' => '',
			'expected_duration' => '',
		)
	);

	$inserted = $wpdb->insert(
		$wpdb->prefix . 'woocommerce_miguel_async_requests',
		$args
	);

	if ( ! $inserted ) {
		return new WP_Error( 'inserting-failed', __( 'Inserting failed.', 'miguel' ) );
	}

	// Last inserted id
	return $wpdb->insert_id;
}

/**
 * Update_async_request
 *
 * @param int   $guid
 * @param array $args
 *
 * @return int|false
 */
function miguel_update_async_request( $guid, $args ) {
	global $wpdb;

	return $wpdb->update(
		$wpdb->prefix . 'woocommerce_miguel_async_requests',
		$args,
		array(
			'guid' => $guid,
		)
	);
}

/**
 * Log
 *
 * @param mixed $data
 */
function miguel_log( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) {
		error_log( print_r( $data, true ) );
	} else {
		error_log( $data );
	}
}

/**
 * @param WC_Order $order
 * @return array
 */
function miguel_get_order_coupons_code( $order ) {
	global $wpdb;

	$codes = array();

	$sql = $wpdb->prepare( "SELECT order_item_name FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d ", $order->get_id() );
	$sql .= "AND order_item_type IN ( '" . implode( "','", array_map( 'esc_sql', array( 'coupon' ) ) ) . "' ) ORDER BY order_item_id;";

	$results = $wpdb->get_results( $sql );
	if ( $results ) {
		$codes = wp_list_pluck( $results, 'order_item_name' );
	}

	return $codes;
}

/**
 * Get order download items
 *
 * @param WC_Order $order
 * @return array
 */
function miguel_get_order_download_items( $order ) {
	$items = array();
	$codes = implode( ',', miguel_get_order_coupons_code( $order ) );

	foreach( $order->get_items() as $item ) {
		$product = $order->get_product_from_item( $item );
		if ( ! $product->is_downloadable() ) {
			continue;
		}

		$downloads = $product->get_downloads();
		foreach( $downloads as $download ) {
			if ( ! miguel_starts_with( $download['file'], '[miguel' ) && ! miguel_starts_with( $download['file'], '[wosa' )) {
				continue;
			}

			$download_args = miguel_get_shortcode_atts( $download['file'], array(
				'id' => ''
			) );

			$download_args['id'] = absint( $download_args['id'] );
			if ( 0 < $download_args['id'] ) {
				$items[] = array(
					'item_id' => $item->get_id(),
					'code' => (string)$download_args['id'],
					'price' => array(
						'regular_without_vat' => $item['line_total'],
						'sold_without_vat' => $item['line_total']
					)
				);
			}
		}
	}

	return $items;
}

/**
 * @param WC_Order $order
 * @return string|null
 */
function miguel_get_order_email( $order ) {

	if ( ! $order instanceof WC_Order ) {
	    return null;
  	}

  	$user_id = $order->get_user_id();
  	if ( 0 < $user_id ) {
    	$user_data = get_user_by( 'id', $user_id );
    	return $user_data->user_email;
  	}

  	return $order->get_billing_email();
}

/**
 * @param WC_Order $order
 * @return bool|WP_Error
 */
function miguel_send_order( $order ) {

	// Contains order audio files?
	$items = miguel_get_order_download_items( $order );
	if ( 0 === sizeof( $items ) ) {
		return new WP_Error( 'invalid-items', __( 'Invalid items.', 'miguel' ) );
	}

	// Checks if order exists in Miguel
	//$order_details = WC_Miguel_Api()->api()->orderdetails( $order->get_id() );
	//if ( !empty( $order_details->code ) && $order_details->code == 'APIError.itemNotFound' ) {


	// Prepares arguments
	$user =  array(
		'id' 		=> (string)$order->get_id(),
		'email' 	=> miguel_get_order_email( $order ),
		'full_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
		'address' 	=> $order->get_billing_address_1()
	);
	$purchase_date = date( 'Y-m-d' ); // TODO: better formatting (include time)

	foreach( $items as $item ) {

		$download_url = get_post_meta( $order->get_id(), '_download_url_' . $item['code'], true );

		if ( empty( $download_url ) ) {

			$args = array(
				'id' 			=> $item['code'],
				'user' 			=> $user,
				'purchase_date' => (string)$purchase_date,
				'order_code'	=> (string)$order->get_id(),
				'sold_price'	=> $item['price']['regular_without_vat']
			);
			$response = miguel()->api()->generate(  $args ); // TODO: create order instead of generate file

			if ( !empty( $response->download_url ) ) {
				update_post_meta( $order->get_id(), '_download_url_' . $item['code'], $response->download_url );
			}

			miguel_log( $args );
			miguel_log( $response );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

		}

	}

	update_post_meta( $order->get_id(), '_miguelapi_call', current_time( 'mysql' ) );

	return true;

}
