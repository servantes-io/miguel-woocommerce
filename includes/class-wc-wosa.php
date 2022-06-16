<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * The main class
 *
 * @package WC_Wosa
 */

class WC_Wosa {

  /**
   * @var string
   */
  public $version = '0.1.0';

  /**
   * @var WC_Wosa
   */
  protected static $instance = null;

  /**
   * @var WC_Wosa_API
   */
  protected $api = null;

  /**
   * @var WC_Logger
   */
  public static $log = null;

  /**
   * @return WC_Wosa
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
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/wc-wosa-functions.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-api.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-file.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-actions.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-install.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-request.php' );
    include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/class-wc-wosa-download.php' );

    if ( is_admin() ) {
      include_once( dirname( WC_WOSA_PLUGIN_FILE ) . '/includes/admin/class-wc-wosa-admin.php' );
    }
  }

  /**
   * Inits hooks.
   */
  public function init_hooks() {
    register_activation_hook( WC_WOSA_PLUGIN_FILE, array( 'WC_Wosa_Install', 'install' ) );
    add_action( 'init', array( $this, 'init' ) );
  }

  /**
   * Localize.
   */
  public function init() {
    load_plugin_textdomain( 'wc-wosa', false, plugin_basename( dirname( WC_WOSA_PLUGIN_FILE ) ) . '/languages/' );
  }

  /**
   * @return WC_Wosa_API
   */
  public function api() {
    if ( is_null( $this->api ) ) {
      $url = get_option( 'wc_wosa_api_url' );
      $token = get_option( 'wc_wosa_api_token' );
      $this->api = new WC_Wosa_API( $url, $token );
    }
    return $this->api;
  }

  /**
   * Log.
   *
   * @param string $message
   */
  public static function log( $message, $type = 'info' ) {
    if ( is_null( self::$log ) ) {
      self::$log = new WC_Logger();
    }
    self::$log->add( 'wosa', strtoupper( $type ) . ' ' . $message );
  }
}
