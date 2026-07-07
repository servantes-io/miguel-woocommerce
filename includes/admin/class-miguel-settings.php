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
	 * Option name controlling whether Miguel's backend sends order emails.
	 */
	const SEND_EMAIL_OPTION = 'miguel_send_order_email';

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
					'id'      => self::SEND_EMAIL_OPTION,
					'type'    => 'checkbox',
					'title'   => __( 'Send order emails from Miguel', 'miguel' ),
					'desc'    => __( "When enabled, Miguel's server sends the order/delivery email to the customer. When disabled, Miguel does not send any email.", 'miguel' ),
					'default' => 'no',
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

		$base_url = $this->get_canonical_shop_url();
		$client   = new Miguel_V2_Client( $api_url, $api_key );
		$result   = $client->connect(
			new Miguel_V2_Connect_Request(
				$this->get_woocommerce_version(),
				$this->get_module_version(),
				$base_url,
				$this->build_base_uri( $base_url )
			)
		);

		if ( true === $result ) {
			update_option( 'miguel_api_connected', 'yes' );
			update_option( 'miguel_api_last_connected_at', gmdate( 'c' ) );
			WC_Admin_Settings::add_message( __( 'Miguel API connection successful.', 'miguel' ) );
			return;
		}

		$code = $result->get_error_code();
		if ( 'miguel.http_401' === $code || 'miguel.http_403' === $code ) {
			Miguel::log( 'Miguel connect unauthorized: ' . $result->get_error_message(), 'error' );
			WC_Admin_Settings::add_error( __( 'Miguel API key is invalid or unauthorized.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		Miguel::log( 'Miguel connect request failed: ' . $code . ' ' . $result->get_error_message(), 'error' );
		WC_Admin_Settings::add_error( __( 'Connection to Miguel API failed. Please verify API key and try again.', 'miguel' ) );
		update_option( 'miguel_api_connected', 'no' );
	}

	/**
	 * Build a canonical base URI path from an absolute shop URL.
	 *
	 * @param string $base_url Absolute shop base URL.
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
