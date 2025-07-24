<?php
/**
 * Mock HTTP response
 *
 * @package Miguel\Tests
 */
class Miguel_Helper_HTTP {

	/**
	 * @var string
	 */
	private static $what;

	/**
	 * @var array
	 */
	private static $responses = array();

	/**
	 * @var array
	 */
	private static $requests = array();

	/**
	 * @param string $what
	 */
	public static function mock_server_response( $what ) {
		self::$what = $what;
		self::$responses = array();
		self::$requests = array();

		add_filter( 'pre_http_request', array( __CLASS__, 'response' ), 10, 3 );
	}

	/**
	 * Mock successful API responses
	 *
	 * @param array $responses Array of responses keyed by URL pattern or method
	 */
	public static function mock_api_responses( $responses ) {
		self::$responses = $responses;
		self::$requests = array();

		add_filter( 'pre_http_request', array( __CLASS__, 'mock_api_response' ), 10, 3 );
	}

	/**
	 * Mock API response handler
	 *
	 * @param false  $response
	 * @param array  $args
	 * @param string $url
	 * @return array
	 */
	public static function mock_api_response( $response, $args, $url ) {
		// Store the request for later verification
		self::$requests[] = array(
			'url' => $url,
			'method' => isset( $args['method'] ) ? $args['method'] : 'GET',
			'body' => isset( $args['body'] ) ? $args['body'] : '',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
		);

		$method = isset( $args['method'] ) ? $args['method'] : 'GET';

		// Check for method-specific responses
		if ( isset( self::$responses[ $method ] ) ) {
			return self::$responses[ $method ];
		}

		// Check for URL pattern responses
		foreach ( self::$responses as $pattern => $mock_response ) {
			if ( strpos( $url, $pattern ) !== false ) {
				return $mock_response;
			}
		}

		// Default successful response
		return array(
			'body' => '{"success": true}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	}

	/**
	 * Get recorded requests for verification
	 *
	 * @return array
	 */
	public static function get_requests() {
		return self::$requests;
	}

	/**
	 * Get the last recorded request
	 *
	 * @return array|null
	 */
	public static function get_last_request() {
		return end( self::$requests ) ?: null;
	}

	/**
	 * @param false  $response
	 * @param array  $args
	 * @param string $url
	 * @return array
	 */
	public static function response( $response, $args, $url ) {
		switch ( self::$what ) {
			case '__return__url':
				$response = $url;
				break;
			case '__return__headers':
				$response = $args['headers'];
				break;
			case '__return__body':
				$response = $args['body'];
				break;
		}

		return array(
			'body' => $response,
			'response' => array( 'code' => 200 ),
		);
	}

	/**
	 * Remove filter.
	 */
	public static function clear() {
		remove_filter( 'pre_http_request', array( __CLASS__, 'response' ) );
		remove_filter( 'pre_http_request', array( __CLASS__, 'mock_api_response' ) );
		self::$responses = array();
		self::$requests = array();
	}
}
