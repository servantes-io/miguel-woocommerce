<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Settings with dependency injection for better testability
 *
 * @package Miguel
 */
class Miguel_Settings extends WC_Settings_Page {

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Init settings page with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
		$this->id = 'miguel';
		$this->label = __( 'Miguel', 'miguel' );

		parent::__construct();
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		$this->hook_manager->add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		$this->hook_manager->add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager_Interface|null
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Get settings config for WooCommerce Settings page.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = array_filter(
			array(
				array(
					'id' => 'miguel_api_options',
					'type' => 'title',
					'title' => __( 'Miguel API', 'miguel' ),
					'desc' => __( 'miguel_settings_description', 'miguel' ),
				),
				array(
					'id' => Miguel_API::API_KEY_OPTION,
					'css' => 'min-width: 350px;',
					'type' => 'text',
					'title' => __( 'API key', 'miguel' ),
					'desc' => __( 'To setup safe communication between your e-shop and our server.', 'miguel' ),
				),
				array(
					'id'      => Miguel_API::SERVER_OPTION,
					'type'    => 'select',
					'title'   => __( 'API server', 'miguel' ),
					'options' => array(
						Miguel_API::ENV_PROD    => __( 'Production', 'miguel' ),
						Miguel_API::ENV_STAGING => __( 'Staging', 'miguel' ),
						Miguel_API::ENV_TEST    => __( 'Test', 'miguel' ),
					),
					'default' => Miguel_API::ENV_PROD,
				),
				array(
					'id' => 'miguel_api_options',
					'type' => 'sectionend',
				),
			)
		);

		return $settings;
	}

	/**
	 * Display settings.
	 */
	public function output() {
		global $current_section;

		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings.
	 */
	public function save() {
		global $current_section;

		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::save_fields( $settings );
		$this->connect_to_miguel_api();
	}

	/**
	 * Attempt to connect using current API settings.
	 *
	 */
	private function connect_to_miguel_api() {
		$api_key = trim( (string) get_option( Miguel_API::API_KEY_OPTION, '' ) );
		if ( '' === $api_key ) {
			WC_Admin_Settings::add_error( __( 'Miguel connection skipped: API key is required.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$api_url = Miguel_API::getServerUrl( Miguel_API::getServer() );
		if ( false === $api_url || '' === trim( (string) $api_url ) ) {
			WC_Admin_Settings::add_error( __( 'Miguel API URL is not configured.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$api = new Miguel_API( $api_url, $api_key );
		$response = $api->connect_woocommerce(
			$this->get_woocommerce_version(),
			$this->get_module_version(),
			$this->get_canonical_shop_url()
		);

		if ( is_wp_error( $response ) ) {
			Miguel::log( 'Miguel connect request failed: ' . $response->get_error_code() . ' ' . $response->get_error_message(), 'error' );
			WC_Admin_Settings::add_error( __( 'Connection to Miguel API failed due to network error. Please verify API key and try again.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$response_message = wp_remote_retrieve_response_message( $response );
		$decoded_body = json_decode( $body, true );
		$body_is_json = JSON_ERROR_NONE === json_last_error();

		if ( 200 === $status_code ) {
			update_option( 'miguel_api_connected', 'yes' );
			update_option( 'miguel_api_last_connected_at', gmdate( 'c' ) );
			WC_Admin_Settings::add_message( __( 'Miguel API connection successful.', 'miguel' ) );

			return;
		}

		if ( 401 === $status_code || 403 === $status_code ) {
			Miguel::log( 'Miguel connect unauthorized response: HTTP ' . $status_code, 'error' );
			WC_Admin_Settings::add_error( __( 'Miguel API key is invalid or unauthorized.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$error_detail = '';
		if ( $body_is_json && is_array( $decoded_body ) ) {
			if ( ! empty( $decoded_body['message'] ) ) {
				$error_detail = (string) $decoded_body['message'];
			} elseif ( ! empty( $decoded_body['error'] ) ) {
				$error_detail = (string) $decoded_body['error'];
			}
		} elseif ( '' === trim( $body ) ) {
			$error_detail = __( 'Empty response body.', 'miguel' );
		} else {
			$error_detail = __( 'Unexpected non-JSON response received.', 'miguel' );
		}

		if ( '' === $error_detail && '' !== $response_message ) {
			$error_detail = $response_message;
		}

		if ( '' === $error_detail ) {
			$error_detail = __( 'Unexpected API response.', 'miguel' );
		}

		WC_Admin_Settings::add_error(
			sprintf(
				/* translators: 1: HTTP status code, 2: API error detail. */
				__( 'Miguel API connection failed (HTTP %1$d): %2$s', 'miguel' ),
				$status_code,
				$error_detail
			)
		);
		update_option( 'miguel_api_connected', 'no' );

		Miguel::log( 'Miguel connect failed: HTTP ' . $status_code . ' ' . sanitize_text_field( (string) $error_detail ), 'error' );
	}

	/**
	 * Get WooCommerce runtime version.
	 *
	 * @return string
	 */
	private function get_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		if ( function_exists( 'WC' ) && WC() && isset( WC()->version ) ) {
			return (string) WC()->version;
		}

		return '';
	}

	/**
	 * Get Miguel plugin module version.
	 *
	 * @return string
	 */
	private function get_module_version() {
		return (string) miguel()->version;
	}

	/**
	 * Build canonical base URL of current shop.
	 *
	 * @return string
	 */
	private function get_canonical_shop_url() {
		$home_url = home_url( '/' );
		$parts = wp_parse_url( $home_url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( $home_url );
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : ( is_ssl() ? 'https' : 'http' );
		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		$canonical_url = $scheme . '://' . $host . $port;
		if ( '' !== $path ) {
			$canonical_url .= '/' . $path;
		}

		return untrailingslashit( $canonical_url );
	}
}
