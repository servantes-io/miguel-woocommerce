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

	const ENV_PROD = 'prod';
	const ENV_STAGING = 'staging';
	const ENV_TEST = 'test';
	const ENV_OWN = 'own';
	const CURRENT_ENV = self::ENV_PROD;

	const API_KEY_OPTION = 'miguel_api_key';

	const MIGUEL_API_BASE_URL = 'https://miguel.servantes.cz';

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
	 * Get default values for API configuration options.
	 *
	 * @return array
	 */
	public static function getDefaultValues() {
		return array(
			self::API_KEY_OPTION => '',
		);
	}

	/**
	 * Get currently selected API environment.
	 *
	 * @return string
	 */
	public static function getServer() {
		return self::CURRENT_ENV;
	}

	/**
	 * Return server URL for given environment.
	 *
	 * @param string $env Environment code.
	 * @return string|false
	 */
	public static function getServerUrl( $env ) {
		switch ( $env ) {
			case self::ENV_PROD:
				return 'https://miguel.servantes.cz';
			case self::ENV_STAGING:
				return 'https://miguel-staging.servantes.cz';
			case self::ENV_TEST:
				return 'https://miguel-test.servantes.cz';
		}

		return false;
	}

	/**
	 * Get API token for selected environment.
	 *
	 * @param string $environment Environment code.
	 * @return string
	 */
	public static function getServerToken( $environment ) {
		return get_option( self::API_KEY_OPTION );
	}

	/**
	 * Check whether API integration is enabled.
	 *
	 * @return bool
	 */
	public static function getEnabled() {
		return '' !== trim( (string) get_option( self::API_KEY_OPTION, '' ) );
	}

	/**
	 * Set API integration enable flag.
	 *
	 * @param bool $enabled Enable state.
	 */
	public static function setEnabled( $enabled ) {
		return;
	}

	/**
	 * Get current API configuration.
	 *
	 * @return array|false
	 */
	public static function getCurrentApiConfiguration() {
		$environment = self::getServer();
		$url = self::getServerUrl( $environment );
		$token = self::getServerToken( $environment );

		if ( false === $url || false === $token ) {
			return false;
		}

		$url = trim( (string) $url );
		$token = trim( (string) $token );

		if ( '' === $url || '' === $token ) {
			return false;
		}

		return array(
			'url' => untrailingslashit( $url ),
			'token' => $token,
			'environment' => $environment,
		);
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return array
	 */
	public static function get_default_values() {
		return self::getDefaultValues();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return string
	 */
	public static function get_environment() {
		return self::getServer();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param string $environment Environment code.
	 * @return string|false
	 */
	public static function get_server_url( $environment = '' ) {
		$environment = '' === $environment ? self::getServer() : $environment;

		return self::getServerUrl( $environment );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param string $environment Environment code.
	 * @return string|false
	 */
	public static function get_server_token( $environment = '' ) {
		$environment = '' === $environment ? self::getServer() : $environment;

		return self::getServerToken( $environment );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return bool
	 */
	public static function get_enabled() {
		return self::getEnabled();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param bool $enabled Enable state.
	 */
	public static function set_enabled( $enabled ) {
		self::setEnabled( $enabled );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return array|false
	 */
	public static function get_current_api_configuration() {
		return self::getCurrentApiConfiguration();
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

		return $this->post( 'v1/generate_' . $format . '/' . urlencode( $book ), $args );
	}

	/**
	 * Submit order to Miguel API
	 *
	 * @param array $order_data Order data array.
	 * @return array|WP_Error
	 */
	public function submit_order( $order_data ) {
		$res = $this->post( 'v1/orders', $order_data );

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
		$res = $this->delete( 'v1/orders/' . urlencode( $order_code ) );

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
	 * Connect WooCommerce shop to Miguel API.
	 *
	 * @param string $wc_version WooCommerce version.
	 * @param string $module_version Miguel plugin version.
	 * @param string $base_url Canonical e-shop base URL.
	 * @return array|WP_Error
	 */
	public function connect_woocommerce( $wc_version, $module_version, $base_url ) {
		$request_guard = $this->validate_outbound_configuration();
		if ( is_wp_error( $request_guard ) ) {
			return $request_guard;
		}

		$base_uri = $this->build_base_uri( $base_url );

		$body = array(
			'wcVersion' => (string) $wc_version,
			'moduleVersion' => (string) $module_version,
			'baseUrl' => (string) $base_url,
			'baseUri' => $base_uri,
		);

		$data = array(
			'method' => 'POST',
			'timeout' => 20,
			'user-agent' => $this->user_agent(),
			'headers' => array(
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $this->token,
			),
			'body' => wp_json_encode( $body ),
		);

		return wp_remote_post( $this->build_url( 'v2/eshop/woocommerce/connect' ), $data );
	}

	/**
	 * Create POST request
	 *
	 * @param string $query
	 * @param array  $body
	 * @return array|WP_Error
	 */
	private function post( $query, $body ) {
		$request_guard = $this->validate_outbound_configuration();
		if ( is_wp_error( $request_guard ) ) {
			return $request_guard;
		}

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
		$request_guard = $this->validate_outbound_configuration();
		if ( is_wp_error( $request_guard ) ) {
			return $request_guard;
		}

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

	/**
	 * Build endpoint URL without duplicate slashes.
	 *
	 * @param string $path Endpoint path.
	 * @return string
	 */
	private function build_url( $path ) {
		return trailingslashit( untrailingslashit( $this->url ) ) . ltrim( $path, '/' );
	}

	/**
	 * Build canonical base URI from absolute URL.
	 *
	 * @param string $base_url Absolute e-shop base URL.
	 * @return string
	 */
	private function build_base_uri( $base_url ) {
		$path = wp_parse_url( (string) $base_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Validate outbound request prerequisites.
	 *
	 * @return true|WP_Error
	 */
	private function validate_outbound_configuration() {
		if ( '' === trim( (string) $this->url ) || '' === trim( (string) $this->token ) ) {
			return new WP_Error( 'configuration.not_set', __( 'Miguel API configuration is not set.', 'miguel' ) );
		}

		return true;
	}

	private function user_agent() {
		return 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/' . get_bloginfo( 'version' ) . '; WooCommerce/' . WC()->version . '; PHP/' . phpversion() . '; ' . get_bloginfo( 'url' );
	}
}
