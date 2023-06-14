<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * The main class
 *
 * @package Miguel
 */

class Miguel {

  /**
   * @var string
   */
  public $version = '1.1.3';

  /**
   * @var Miguel
   */
  protected static $instance = null;

  /**
   * @var Miguel_API
   */
  protected $api = null;

  /**
   * @var WC_Logger
   */
  public static $log = null;

  /**
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
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/miguel-functions.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-api.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-file.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-actions.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-install.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-request.php' );
    include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/class-miguel-download.php' );

    if ( is_admin() ) {
      include_once( dirname( MIGUEL_PLUGIN_FILE ) . '/includes/admin/class-miguel-admin.php' );
    }
  }

  /**
   * Inits hooks.
   */
  public function init_hooks() {
    register_activation_hook( MIGUEL_PLUGIN_FILE, array( 'Miguel_Install', 'install' ) );
    add_action( 'init', array( $this, 'init' ) );

    // add links to plugins page
    add_filter('plugin_action_links_miguel/miguel.php', array($this, 'settings_link'));
  }

  /**
   * Localize.
   */
  public function init() {
    load_plugin_textdomain( 'miguel', false, plugin_basename( dirname( MIGUEL_PLUGIN_FILE ) ) . '/languages/' );
  }

  /**
   * @return Miguel_API
   */
  public function api() {
    if ( is_null( $this->api ) ) {
      $env = get_option( 'miguel_api_env' );
      $url = 'https://miguel.servantes.cz/v1/';
      if ($env == 'staging') {
        $url = 'https://miguel-staging.servantes.cz/v1/';
      } else if ($env == 'test') {
        $url = 'https://miguel-test.servantes.cz/v1/';
      }

      $token = get_option( 'miguel_api_key' );
      $this->api = new Miguel_API( $url, $token );
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
    self::$log->add( 'miguel', strtoupper( $type ) . ' ' . $message );
  }

  /**
   * Add links to plugins page
   */
  public function settings_link($links) {
    // Build and escape the URL.
    $url = esc_url(add_query_arg([
      'page' => 'wc-settings',
      'tab' => 'miguel',
    ], get_admin_url() . 'admin.php'));

    // Create the link.
    $settings_link = "<a href='$url'>" . __( 'Settings' ) . '</a>';

    // Adds the link to the end of the array.
    array_unshift($links, $settings_link);

    return $links;
  }
}
