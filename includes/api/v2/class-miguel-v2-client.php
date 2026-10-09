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
	private string $url;

	/** @var string */
	private string $token;

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

		$path      = 'v2/product-variants/' . rawurlencode( $variant_code ) . '/watermarked-file';
		$operation = 'POST /' . $path;
		$response  = $this->send( 'POST', $path, $request->to_array() );
		if ( is_wp_error( $response ) ) {
			$this->report_unreachable( $operation, $response );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$this->report_response( 'MIGUEL_RESPONSE_UNPARSABLE', json_last_error_msg(), $operation, $response );
			} elseif ( ! is_array( $decoded ) || ! isset( $decoded['downloadUrl'] ) || ! is_string( $decoded['downloadUrl'] ) || '' === $decoded['downloadUrl'] ) {
				$this->report_response( 'MIGUEL_RESPONSE_INVALID', 'downloadUrl is missing or not a string', $operation, $response );
			}

			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'miguel', __( 'Something went wrong.', 'miguel' ) );
			}
			return $decoded;
		}

		return $this->problem_to_wp_error( $response, $operation );
	}

	/**
	 * Create (sync) an order.
	 *
	 * @param Miguel_V2_Order_Create $order Order DTO.
	 * @return true|WP_Error
	 */
	public function create_order( Miguel_V2_Order_Create $order ) {
		$body     = $order->to_array();
		$context  = array( 'orderId' => $body['code'] );
		$response = $this->send( 'POST', 'v2/orders', $body );
		if ( is_wp_error( $response ) ) {
			$this->report_unreachable( 'POST /v2/orders', $response, $context );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 201 === $code ) {
			return true;
		}

		return $this->problem_to_wp_error( $response, 'POST /v2/orders', $context );
	}

	/**
	 * Delete an order (idempotent — 404 treated as success).
	 *
	 * @param string $code Order code.
	 * @return true|WP_Error
	 */
	public function delete_order( $code ) {
		$path      = 'v2/orders/' . rawurlencode( (string) $code );
		$operation = 'DELETE /' . $path;
		$context   = array( 'orderId' => (string) $code );
		$response  = $this->send( 'DELETE', $path );
		if ( is_wp_error( $response ) ) {
			$this->report_unreachable( $operation, $response, $context );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 204 === $status || 404 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response, $operation, $context );
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
			$this->report_unreachable( 'POST /v2/eshop/woocommerce/connect', $response );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return true;
		}

		return $this->problem_to_wp_error( $response, 'POST /v2/eshop/woocommerce/connect' );
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
			'user-agent' => self::user_agent(),
			'headers'    => array(
				'Authorization'   => 'Bearer ' . $this->token,
				'Accept-Language' => get_user_locale(),
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( trailingslashit( $this->url ) . ltrim( $path, '/' ), $args );

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			Miguel_Error_Reporter::schedule_flush();
		}

		return $response;
	}

	/**
	 * Report a call that never got an answer from Miguel (DNS, TLS, connection, timeout).
	 *
	 * @param string   $operation Method and path of the call.
	 * @param WP_Error $error     Error from send().
	 * @param array    $context   Ids identifying what the call was about.
	 */
	private function report_unreachable( $operation, $error, $context = array() ) {
		if ( 'configuration.not_set' === $error->get_error_code() ) {
			return;
		}

		Miguel_Error_Reporter::report(
			'MIGUEL_UNREACHABLE',
			$error->get_error_message(),
			array(
				'operation' => $operation,
				'context'   => $context,
			)
		);
	}

	/**
	 * Report an answer from Miguel the plugin could not use.
	 *
	 * @param string $code      Error code.
	 * @param string $message   What was wrong with it.
	 * @param string $operation Method and path of the call.
	 * @param array  $response  wp_remote_* response.
	 * @param array  $context   Ids identifying what the call was about.
	 */
	private function report_response( $code, $message, $operation, $response, $context = array() ) {
		Miguel_Error_Reporter::report(
			$code,
			$message,
			array(
				'operation'       => $operation,
				'httpStatus'      => (int) wp_remote_retrieve_response_code( $response ),
				'responseExcerpt' => wp_remote_retrieve_body( $response ),
				'context'         => $context,
			)
		);
	}

	/**
	 * Convert a v2 IProblem error response into a WP_Error.
	 *
	 * Also reports it: 401/403 as MIGUEL_AUTH_REJECTED, any other 4xx/5xx as MIGUEL_HTTP_ERROR.
	 *
	 * @param array  $response  wp_remote_* response.
	 * @param string $operation Method and path of the call.
	 * @param array  $context   Ids identifying what the call was about.
	 * @return WP_Error
	 */
	private function problem_to_wp_error( $response, $operation, $context = array() ) {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$title  = ( is_array( $decoded ) && ! empty( $decoded['title'] ) ) ? $decoded['title'] : wp_remote_retrieve_response_message( $response );
		$detail = ( is_array( $decoded ) && ! empty( $decoded['detail'] ) ) ? $decoded['detail'] : '';

		$message = trim( $title . ( '' !== $detail ? ': ' . $detail : '' ) );
		if ( '' === $message ) {
			$message = __( 'Something went wrong.', 'miguel' );
		}

		if ( 401 === $status || 403 === $status ) {
			$this->report_response( 'MIGUEL_AUTH_REJECTED', $message, $operation, $response, $context );
		} elseif ( $status >= 400 ) {
			$this->report_response( 'MIGUEL_HTTP_ERROR', $message, $operation, $response, $context );
		}

		return new WP_Error( 'miguel.http_' . $status, $message );
	}

	/**
	 * Build the outbound user-agent string.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; PHP/' . phpversion() . '; ' . get_bloginfo( 'url' );
	}
}
