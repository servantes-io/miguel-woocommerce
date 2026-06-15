<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Typed Miguel v2 API client (outbound).
 *
 * @package Miguel
 */
class Miguel_V2_Client {

	/**
	 * Formats the plugin is allowed to request.
	 */
	const ALLOWED_FORMATS = array( 'epub', 'mobi', 'pdf', 'audio' );

	/** @var string */
	private $url;

	/** @var string */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $url   API base URL.
	 * @param string $token Bearer token.
	 */
	public function __construct( $url, $token ) {
		$this->url   = untrailingslashit( (string) $url );
		$this->token = (string) $token;
	}

	/**
	 * Request a watermarked file for a product variant.
	 *
	 * @param string                             $variant_code Product variant code.
	 * @param Miguel_V2_Watermarked_File_Request $request      Request DTO.
	 * @return array|WP_Error Decoded body ({ downloadUrl, downloadExpiresAt, task }) or error.
	 */
	public function get_watermarked_file( $variant_code, Miguel_V2_Watermarked_File_Request $request ) {
		if ( ! in_array( $request->get_target(), self::ALLOWED_FORMATS, true ) ) {
			return new WP_Error( 'miguel', __( 'Format is not allowed.', 'miguel' ) );
		}

		$response = $this->send( 'POST', 'v2/product-variants/' . rawurlencode( $variant_code ) . '/watermarked-file', $request->to_array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'miguel', __( 'Something went wrong.', 'miguel' ) );
			}
			return $decoded;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Create (sync) an order.
	 *
	 * @param Miguel_V2_Order_Create $order Order DTO.
	 * @return true|WP_Error
	 */
	public function create_order( Miguel_V2_Order_Create $order ) {
		$response = $this->send( 'POST', 'v2/orders', $order->to_array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 201 === $code ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Delete an order (idempotent — 404 treated as success).
	 *
	 * @param string $code Order code.
	 * @return true|WP_Error
	 */
	public function delete_order( $code ) {
		$response = $this->send( 'DELETE', 'v2/orders/' . rawurlencode( (string) $code ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status || 404 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Connect the WooCommerce shop to Miguel.
	 *
	 * @param Miguel_V2_Connect_Request $request Connect DTO.
	 * @return true|WP_Error
	 */
	public function connect( Miguel_V2_Connect_Request $request ) {
		$response = $this->send( 'POST', 'v2/eshop/woocommerce/connect', $request->to_array(), 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response );
	}

	/**
	 * Send an HTTP request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path relative to the base URL.
	 * @param array|null $body    Optional JSON body.
	 * @param int        $timeout Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function send( $method, $path, $body = null, $timeout = 180 ) {
		if ( '' === trim( $this->url ) || '' === trim( $this->token ) ) {
			return new WP_Error( 'configuration.not_set', __( 'Miguel API configuration is not set.', 'miguel' ) );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => $this->user_agent(),
			'headers'    => array(
				'Authorization'   => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		return wp_remote_request( trailingslashit( $this->url ) . ltrim( $path, '/' ), $args );
	}

	/**
	 * Convert a v2 IProblem error response into a WP_Error.
	 *
	 * @param array $response wp_remote_* response.
	 * @return WP_Error
	 */
	private function problem_to_wp_error( $response ) {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$title  = ( is_array( $decoded ) && ! empty( $decoded['title'] ) ) ? $decoded['title'] : wp_remote_retrieve_response_message( $response );
		$detail = ( is_array( $decoded ) && ! empty( $decoded['detail'] ) ) ? $decoded['detail'] : '';

		$message = trim( $title . ( '' !== $detail ? ': ' . $detail : '' ) );
		if ( '' === $message ) {
			$message = __( 'Something went wrong.', 'miguel' );
		}

		return new WP_Error( 'miguel.http_' . $status, $message );
	}

	/**
	 * Build the outbound user-agent string.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; PHP/' . phpversion() . '; ' . get_bloginfo( 'url' );
	}
}
