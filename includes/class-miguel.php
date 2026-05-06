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
	public $version = '1.6.1';

	/**
	 * Instance
	 *
	 * @var Miguel
	 */
	protected static $instance = null;

	/**
	 * Container for dependency injection
	 *
	 * @var Miguel_Container
	 */
	private $container;

	/**
	 * Hook manager for centralized hook registration
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

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
		$this->container = new Miguel_Container();
		$this->hook_manager = new Miguel_Hook_Manager();
		$this->register_services();
		$this->init_hooks();
	}

	/**
	 * Includes required files.
	 */
	public function includes() {
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/interface-miguel-hook-manager.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-hook-manager.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-container.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/miguel-functions.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-api.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-file.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-install.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-order-utils.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-request.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-download.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-orders.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/trait-miguel-rest-auth.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-product-code-resolver.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-products-api.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-product-code-map-api.php';
		include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-order-create-api.php';

		if ( is_admin() ) {
			include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-admin.php';
		}
	}

	/**
	 * Register services in the container
	 */
	private function register_services() {
		$this->container->register( 'hook_manager', function () {
			return $this->hook_manager;
		} );

		$this->container->register( 'api', function () {
			$configuration = Miguel_API::getCurrentApiConfiguration();
			$url = Miguel_API::MIGUEL_API_BASE_URL;
			$token = '';

			if ( false !== $configuration ) {
				$url = $configuration['url'];
				$token = $configuration['token'];
			}

			return new Miguel_API( $url, $token );
		} );

		$this->container->register( 'download', function ( $container ) {
			return new Miguel_Download(
				$container->get( 'hook_manager' ),
				$container->get( 'api' )
			);
		} );

		$this->container->register( 'orders', function ( $container ) {
			return new Miguel_Orders(
				$container->get( 'hook_manager' ),
				$container->get( 'api' )
			);
		} );

		$this->container->register( 'products_api', function ( $container ) {
			return new Miguel_Products_Api(
				$container->get( 'hook_manager' )
			);
		} );

		$this->container->register( 'product_code_map_api', function ( $container ) {
			return new Miguel_Product_Code_Map_Api(
				$container->get( 'hook_manager' )
			);
		} );

		$this->container->register( 'order_create_api', function ( $container ) {
			return new Miguel_Order_Create_Api(
				$container->get( 'hook_manager' )
			);
		} );

		$this->container->register( 'settings', function ( $container ) {
			include_once dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-settings.php';
			return new Miguel_Settings( $container->get( 'hook_manager' ) );
		} );

		$this->container->register( 'admin', function ( $container ) {
			return new Miguel_Admin(
				$container->get( 'hook_manager' ),
				$container
			);
		} );
	}

	/**
	 * Inits hooks.
	 */
	public function init_hooks() {
		register_activation_hook( MIGUEL_PLUGIN_FILE, array( 'Miguel_Install', 'install' ) );
		$this->hook_manager->add_action( 'init', array( $this, 'init' ) );

		// Add links to plugins page.
		$this->hook_manager->add_filter( 'plugin_action_links_miguel/miguel.php', array( $this, 'settings_link' ) );

		// add support for WC's HPOS https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book
		$this->hook_manager->add_action( 'before_woocommerce_init', function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MIGUEL_PLUGIN_FILE, true );
			}
		} );

		if ( ! defined( 'MIGUEL_TESTS' ) ) {
			// Initialize services and register their hooks
			$this->container->get( 'download' )->register_hooks();
			$this->container->get( 'orders' )->register_hooks();
			$this->container->get( 'products_api' )->register_hooks();
			$this->container->get( 'product_code_map_api' )->register_hooks();
			$this->container->get( 'order_create_api' )->register_hooks();

			// Initialize admin services only in admin context
			if ( is_admin() ) {
				$this->container->get( 'admin' )->register_hooks();
			}
		}
	}

	/**
	 * Localize.
	 */
	public function init() {
		load_plugin_textdomain( 'miguel', false, plugin_basename( dirname( MIGUEL_PLUGIN_FILE ) ) . '/languages/' );
	}

	/**
	 * Log.
	 *
	 * @param string $message Message.
	 * @param string $type    Type.
	 */
	public static function log( $message, $type = 'info' ) {
		wc_get_logger()->log( $type, $message, array( 'source' => 'miguel' ) );
	}

	/**
	 * Write a structured debug entry to a dedicated file.
	 *
	 * @param string $message Debug message.
	 * @param array  $context Additional context.
	 */
	public static function debug_log( $message, $context = array() ) {
		$log_file = self::resolve_debug_log_path( true );
		$payload = array(
			'timestamp' => gmdate( 'c' ),
			'message' => (string) $message,
			'context' => is_array( $context ) ? $context : array(),
		);

		$line = wp_json_encode( $payload );
		if ( false === $line ) {
			$line = print_r( $payload, true );
		}

		if ( '' === $log_file ) {
			self::log( 'Miguel debug log path is unavailable. Message: ' . (string) $message, 'warning' );
			return;
		}

		$result = @file_put_contents( $log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
		if ( false === $result ) {
			self::log( 'Unable to write Miguel debug log file at ' . $log_file . '. Message: ' . (string) $message, 'error' );
		}
	}

	/**
	 * Get debug log file path.
	 *
	 * @return string
	 */
	public static function get_debug_log_path() {
		return self::resolve_debug_log_path( false );
	}

	/**
	 * Resolve debug log file path.
	 *
	 * @param bool $ensure_directory Whether to create the directory.
	 * @return string
	 */
	private static function resolve_debug_log_path( $ensure_directory ) {
		$candidate_dirs = array();
		$log_file_name = 'miguel-debug-' . gmdate( 'd-m-Y' ) . '.log';

		$upload_dir = wp_upload_dir();
		if ( isset( $upload_dir['basedir'] ) && '' !== (string) $upload_dir['basedir'] ) {
			$candidate_dirs[] = trailingslashit( (string) $upload_dir['basedir'] ) . 'miguel-logs';
		}

		if ( defined( 'WP_CONTENT_DIR' ) && '' !== (string) WP_CONTENT_DIR ) {
			$candidate_dirs[] = trailingslashit( WP_CONTENT_DIR ) . 'uploads/miguel-logs';
		}

		$candidate_dirs = array_unique( array_filter( $candidate_dirs ) );
		foreach ( $candidate_dirs as $log_dir ) {
			if ( $ensure_directory && ! file_exists( $log_dir ) && ! wp_mkdir_p( $log_dir ) ) {
				continue;
			}

			if ( file_exists( $log_dir ) && ! is_dir( $log_dir ) ) {
				continue;
			}

			if ( file_exists( $log_dir ) && ! is_writable( $log_dir ) ) {
				continue;
			}

			return trailingslashit( $log_dir ) . $log_file_name;
		}

		return '';
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

	/**
	 * Get the service container
	 *
	 * @return Miguel_Container
	 */
	public function get_container() {
		return $this->container;
	}

	/**
	 * Get the hook manager
	 *
	 * @return Miguel_Hook_Manager_Interface
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Reset the instance (useful for testing)
	 */
	public static function reset_instance() {
		if ( self::$instance && self::$instance->hook_manager ) {
			self::$instance->hook_manager->remove_all_hooks();
		}
		self::$instance = null;
	}
}
