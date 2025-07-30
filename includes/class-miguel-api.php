<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * API client
 *
 * @package Miguel
 */
class Miguel_API {

	/**
	 * URL
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * Token
	 *
	 * @var string
	 */
	protected $token;

	/**
	 * Constructor
	 *
	 * @param string $url
	 * @param string $token
	 */
	public function __construct( $url, $token ) {
		$this->url = $url;
		$this->token = $token;
	}

	/**
	 * Get URL
	 *
	 * @return string
	 */
	public function get_url() {
		return trailingslashit( $this->url );
	}

	/**
	 * Generate
	 *
	 * @param string $book
	 * @param string $format
	 * @param array  $args
	 * @return array|WP_Error
	 */
	public function generate( $book, $format, $args ) {
		if ( ! in_array( $format, array( 'epub', 'mobi', 'pdf', 'audio' ) ) ) {
			return new WP_Error( 'miguel', __( 'Format is not allowed.', 'miguel' ) );
		}

		return $this->post( 'generate_' . $format . '/' . urlencode( $book ), $args );
	}

	/**
	 * Submit order to Miguel API
	 *
	 * @param array $order_data Order data array.
	 * @return array|WP_Error
	 */
	public function submit_order( $order_data ) {
		$res = $this->post( 'orders', $order_data );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 200 === $res['response']['code'] || 201 === $res['response']['code'] ) {
			return $res;
		}

		Miguel::log( 'Failed to submit order: ' . $res['response']['code'] . ' ' . $res['response']['message'] . ' ' . $res['body'], 'error' );

		return new WP_Error( 'miguel', __( 'Failed to delete order.', 'miguel' ) );
	}

	/**
	 * Delete order from Miguel API
	 *
	 * @param string $order_code Order code/ID to delete.
	 * @return array|WP_Error
	 */
	public function delete_order( $order_code ) {
		$res = $this->delete( 'orders/' . urlencode( $order_code ) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 200 === $res['response']['code'] || 404 === $res['response']['code'] ) {
			return $res;
		}

		Miguel::log( 'Failed to delete order: ' . $res['response']['code'] . ' ' . $res['response']['message'] . ' ' . $res['body'], 'error' );

		return new WP_Error( 'miguel', __( 'Failed to delete order.', 'miguel' ) );
	}

	/**
	 * Create POST request
	 *
	 * @param string $query
	 * @param array  $body
	 * @return array|WP_Error
	 */
	private function post( $query, $body ) {
		$data = array(
			'method' => 'POST',
			'timeout' => 180,
			'user-agent' => $this->user_agent(),
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
			'body' => wp_json_encode( $body ),
		);

		return wp_remote_post( $this->get_url() . $query, $data );
	}

	/**
	 * Create DELETE request
	 *
	 * @param string $query
	 * @return array|WP_Error
	 */
	private function delete( $query ) {
		$data = array(
			'method' => 'DELETE',
			'timeout' => 180,
			'user-agent' => $this->user_agent(),
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
		);

		return wp_remote_request( $this->get_url() . $query, $data );
	}

	private function user_agent() {
		return 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; PHP/' . phpversion() . '; ' . get_bloginfo( 'url' );
	}
}
