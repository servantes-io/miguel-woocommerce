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
 * @param string $haystack
 * @param string $needle
 * @return boolean
 */
function miguel_starts_with( $haystack, $needle ) {
  return $needle === "" || strpos( $haystack, $needle ) === 0;
}

/**
 * @param string $shortcode
 * @param array $defaults
 * @param array
 */
function miguel_get_shortcode_atts( $shortcode, $defaults = array() ) {
  return shortcode_atts( $defaults, shortcode_parse_atts( trim( $shortcode, '[]' ) ) );
}

/**
 * @param string $tpl
 * @param array $data
 * @return string
 */
function miguel_get_template( $tpl, $data = array() ) {
  $path = dirname( MIGUEL_PLUGIN_FILE ) . '/includes/views/' . $tpl . '.php';
  if ( ! file_exists( $path ) ) {
    return;
  }

  extract( $data );

  ob_start();

  include( $path );

  return ob_get_clean();
}

/**
 * @param int $product_id
 * @param int $download_id
 * @return Miguel_File|WP_Error
 */
function miguel_get_file( $product_id, $download_id ) {
  try {
    $file = new Miguel_File( $product_id, $download_id );
  } catch( \Exception $e ) {
    return new WP_Error( 'invalid-file', $e->getMessage() );
  }
  return $file;
}

/**
 * @param Miguel_File $file
 * @param Miguel_Request $request
 */
function miguel_get_file_download_url( $file, $request ) {
  global $wpdb;

  return $wpdb->get_row( $wpdb->prepare("
    SELECT * FROM {$wpdb->prefix}woocommerce_miguel_async_requests
    WHERE order_id = %d AND product_id = %d AND download_id = %d
  ", $request->get_order_id(), $file->get_product_id(), $file->get_download_id() ) );
}

/**
 * @param int $guid
 * @return stdClass
 */
function miguel_get_async_request( $guid ) {
  global $wpdb;

  return $wpdb->get_row( $wpdb->prepare("
    SELECT * FROM {$wpdb->prefix}woocommerce_miguel_async_requests
    WHERE guid = %d
  ", absint( $guid ) ) );
}

/**
 * @param array $args
 * @return int
 */
function miguel_insert_async_request( $args ) {
  global $wpdb;

  $args = wp_parse_args( $args, array(
    'status' => 'awaiting',
    'created' => current_time( 'mysql' ),
    'order_id' => '',
    'product_id' => '',
    'download_id' => '',
    'expected_duration' => ''
  ) );

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
 * @param int $guid
 * @param array $args
 * @return int|false
 */
function miguel_update_async_request( $guid, $args ) {
  global $wpdb;

  return $wpdb->update(
    $wpdb->prefix . 'woocommerce_miguel_async_requests',
    $args,
    array(
      'guid' => $guid
    )
  );
}

/**
 * @param mixed
 */
function miguel_log( $data ) {
  if ( is_array( $data ) || is_object( $data ) ) {
    error_log( print_r( $data, true ) );
  } else {
    error_log( $data );
  }
}
