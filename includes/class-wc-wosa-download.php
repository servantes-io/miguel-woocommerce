<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Download handler
 *
 * @package WC_Wosa
 */
class WC_Wosa_Download {

  /**
   * Add action.
   */
  public function __construct() {
    add_action( 'woocommerce_download_product', array( $this, 'download' ), 10, 6 );
  }

  /**
   * @param string $email
   * @param string $order_key
   * @param int $product_id
   * @param int $user_id
   * @param int $download_id
   * @param int $order_id
   */
  public function download( $email, $order_key, $product_id, $user_id, $download_id, $order_id ) {
    $file = wc_wosa_get_file( $product_id, $download_id );
    if ( is_wp_error( $file ) ) {
      return;
    }

    if ( ! $file->is_valid() ) {
      wp_die( __( 'Invalid shortcode params.', 'wc-wosa' ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
      wp_die( __( 'Invalid order.', 'wc-wosa' ) );
    }

    $this->serve( $file, $order );
  }

  /**
   * @param WC_Wosa_File $file
   * @param WC_Order $order
   */
  public function serve( $file, $order ) {
    $request = new WC_Wosa_Request( $order );
    if ( ! $request->is_valid() ) {
      wp_die( __( 'Invalid request.', 'wc-wosa' ) );
    }

    // Async generation
    if ( 'yes' === get_option( 'wc_wosa_async_gen' ) ) {
      $this->serve_async_file( $file, $request );
    } else {
      $this->serve_file( $file, $request );
    }
  }

  /**
   * @param WC_Wosa_File $file
   * @param WC_Wosa_Request $request
   */
  public function serve_file( $file, $request ) {
    $response = WC_Wosa()->api()->generate( $file->get_name(), $file->get_format(), $request->to_array() );
    if ( is_wp_error( $response ) ) {
      wp_die( $response->get_error_message() );
    }

    $json = json_decode( $response['body'] );
    if ( $json && property_exists( $json, 'error' ) && $json->error ) {
      wp_die( $json->reason );
    }

    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename=' . $file->get_filename() );
    header( 'Content-Transfer-Encoding: binary' );
    header( 'Expires: 0' );
    header( 'Cache-Control: must-revalidate' );
    header( 'Pragma: public' );
    header( 'Content-Length: ' . $response['headers']['content-length'] );
    echo $response['body'];
    exit;
  }

  /**
   * @param WC_Wosa_File $file
   * @param WC_Wosa_Request $request
   */
  public function serve_async_file( $file, $request ) {
    $exists = wc_wosa_get_file_download_url( $file, $request );
    if ( ! $exists ) {
      $this->new_async_request( $file, $request );
    }

    $content = __( 'Something went wrong.', 'wc-wosa' );

    switch( $exists->status ) {
      case 'awaiting':
        $content = sprintf(
          '<p>%s</p>',
          __( 'Please be patient, your book is being prepared. Try downloading the file later.', 'wc-wosa' )
        );
        break;
      case 'completed':
        $current = current_time( 'timestamp' );
        // Expires url?
        if ( $current > strtotime( $exists->download_url_expires ) ) {
          $this->new_async_request( $file, $request );
        } else {
          $content = sprintf(
            '<p>%s</p><p><a class="btn" href="%s">%s</a></p>',
            __( 'Your book is ready to download.', 'wc-wosa' ),
            esc_url( $exists->download_url ),
            __( 'Download a file', 'wc-wosa' )
          );
        }
        break;
    }

    $this->wosa_die( $content );
  }

  /**
   * @param WC_Wosa_File $file
   * @param WC_Wosa_Request $request
   */
  public function new_async_request( $file, $request ) {
    $response = WC_Wosa()->api()->generate_async( $file->get_name(), $file->get_format(), $request->to_array() );
    if ( is_wp_error( $response ) ) {
      wp_die( $response->get_error_message() );
    }

    wc_wosa_insert_async_request( array(
      'guid' => $response->id,
      'order_id' => $request->get_order_id(),
      'product_id' => $file->get_product_id(),
      'download_id' => $file->get_download_id(),
      'expected_duration' => $response->expected_duration
    ) );

    $this->wosa_die( wc_wosa_get_template( 'timer', array(
      'time' => $response->expected_duration + 10
    ) ) );
  }

  /**
   * @param string $content
   */
  public function wosa_die( $content ) {
    echo wc_wosa_get_template( 'die', array(
      'content' => $content
    ) );
    die();
  }
}

return new WC_Wosa_Download();
