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
