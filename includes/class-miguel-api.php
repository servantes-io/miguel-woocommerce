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
			'user-agent' => 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; ' . get_bloginfo( 'url' ),
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
			'body' => wp_json_encode( $body ),
		);

		return wp_remote_post( $this->get_url() . $query, $data );
	}
}
