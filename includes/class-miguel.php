<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The main class
 *
 * @package Miguel
 */
class Miguel {

	/**
	 * Version
	 *
	 * @var string
	 */
	public $version = '1.3.0';

	/**
	 * Instance
	 *
	 * @var Miguel
	 */
	protected static $instance = null;

	/**
	 * Api
	 *
	 * @var Miguel_API
	 */
	protected $api = null;

	/**
	 * Log
	 *
	 * @var WC_Logger
	 */
	public static $log = null;

	/**
	 * Get instance
	 *
	 * @return Miguel
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize.
	 */
	public function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Includes required files.
	 */
	public function includes() {
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/miguel-functions.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-api.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-file.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-install.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-request.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-download.php';

		if ( is_admin() ) {
			include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-admin.php';
		}
	}

	/**
	 * Inits hooks.
	 */
	public function init_hooks() {
		register_activation_hook( MIGUEL_PLUGIN_FILE, array( 'Miguel_Install', 'install' ) );
		add_action( 'init', array( $this, 'init' ) );

		// Add links to plugins page.
		add_filter( 'plugin_action_links_miguel/miguel.php', array( $this, 'settings_link' ) );

		// add support for WC's HPOS https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MIGUEL_PLUGIN_FILE, true );
			}
		} );
	}

	/**
	 * Localize.
	 */
	public function init() {
		load_plugin_textdomain( 'miguel', false, plugin_basename( dirname( MIGUEL_PLUGIN_FILE ) ) . '/languages/' );
	}

	/**
	 * Get api
	 *
	 * @return Miguel_API
	 */
	public function api() {
		if ( is_null( $this->api ) ) {
			$url = 'https://miguel.servantes.cz/v1/';
			$token = get_option( 'miguel_api_key' );
			$this->api = new Miguel_API( $url, $token );
		}
		return $this->api;
	}

	/**
	 * Log.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 */
	public static function log( $message, $type = 'info' ) {
		if ( is_null( self::$log ) ) {
			self::$log = new WC_Logger();
		}
		self::$log->add( 'miguel', strtoupper( $type ) . ' ' . $message );
	}

	/**
	 * Add links to plugins page
	 *
	 * @param array $links Links.
	 */
	public function settings_link( $links ) {
		// Build and escape the URL.
		$url = esc_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab' => 'miguel',
				),
				get_admin_url() . 'admin.php'
			)
		);

		// Create the link.
		$settings_link = "<a href='$url'>" . esc_html__( 'Settings', 'miguel' ) . '</a>';

		// Adds the link to the end of the array.
		array_unshift( $links, $settings_link );

		return $links;
	}
}
