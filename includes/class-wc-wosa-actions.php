<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Actions
 *
 * @package WC_Wosa
 */
class WC_Wosa_Actions {

  /**
   * Adds hooks.
   */
  public function __construct() {
    $actions = array(
      'wc_wosa_process_notify' => true
    );

    foreach( $actions as $action => $nopriv ) {
      add_action( 'admin_post_'. $action, array( $this, 'process_notify' ) );
      if ( $nopriv ) {
        add_action( 'admin_post_nopriv_'. $action, array( $this, 'process_notify' ) );
      }
    }
  }

  /**
   * Processes notify from WOSA server.
   */
  public function process_notify() {
    $body = file_get_contents( 'php://input' );
    if ( ! $body ) {
      return;
    }

    $json = json_decode( $body );
    if ( ! $json || ! property_exists( $json, 'id' ) ) {
      return;
    }

    $req = wc_wosa_get_async_request( $json->id );
    if ( ! $req ) {
      return;
    }

    switch( $json->status ) {
      case 'succeeded':
        // + 7 days
        $expires = current_time( 'timestamp' ) + 7 * 24 * 60 * 60;
        wc_wosa_update_async_request( $req->guid, array(
          'status' => 'completed',
          'download_url' => $json->download_url,
          'download_url_expires' => get_date_from_gmt( $json->download_expires, 'Y-m-d H:i:s' )
        ) );
        break;
      case 'failed':
        wc_wosa_update_async_request( $req->guid, array(
          'status' => 'failed'
        ) );
        break;
    }

    wp_send_json_success();
  }
}

return new WC_Wosa_Actions();
