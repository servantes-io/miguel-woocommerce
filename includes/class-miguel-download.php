<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Download handler
 *
 * @package Miguel
 */
class Miguel_Download {

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
    $file = miguel_get_file( $product_id, $download_id );
    if ( is_wp_error( $file ) ) {
      return;
    }

    if ( ! $file->is_valid() ) {
      wp_die( __( 'Invalid shortcode params.', 'miguel' ) );
    }

    $order = wc_get_order( $order_id );
    $item = $this->get_item($order, $download_id);

    if ( ! $order ) {
      wp_die( __( 'Invalid order.', 'miguel' ) );
    }

    $this->serve($file, $order, $item);
  }

  /**
   * @param Miguel_File $file
   * @param WC_Order $order
   * @param WC_Order_Item_Product $item
   */
  public function serve($file, $order, $item) {
    $request = new Miguel_Request($order, $item);
    if ( ! $request->is_valid() ) {
      wp_die( __( 'Invalid request.', 'miguel' ) );
    }

    // Async generation
    if ( 'yes' === get_option( 'miguel_async_gen' ) ) {
      $this->serve_async_file( $file, $request );
    } else {
      $this->serve_file( $file, $request );
    }
  }

  /**
   * @param Miguel_File $file
   * @param Miguel_Request $request
   */
  public function serve_file( $file, $request ) {
    $response = Miguel()->api()->generate( $file->get_name(), $file->get_format(), $request->to_array() );
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
   * @param Miguel_File $file
   * @param Miguel_Request $request
   */
  public function serve_async_file( $file, $request ) {
    $exists = miguel_get_file_download_url( $file, $request );
    if ( ! $exists ) {
      $this->new_async_request( $file, $request );
    }

    $content = __( 'Something went wrong.', 'miguel' );

    switch( $exists->status ) {
      case 'awaiting':
        $content = sprintf(
          '<p>%s</p>',
          __( 'Please be patient, your book is being prepared. Try downloading the file later.', 'miguel' )
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
            __( 'Your book is ready to download.', 'miguel' ),
            esc_url( $exists->download_url ),
            __( 'Download a file', 'miguel' )
          );
        }
        break;
    }

    $this->miguel_die( $content );
  }

  /**
   * @param Miguel_File $file
   * @param Miguel_Request $request
   */
  public function new_async_request( $file, $request ) {
    $response = Miguel()->api()->generate_async( $file->get_name(), $file->get_format(), $request->to_array() );
    if ( is_wp_error( $response ) ) {
      wp_die( $response->get_error_message() );
    }

    miguel_insert_async_request( array(
      'guid' => $response->id,
      'order_id' => $request->get_order_id(),
      'product_id' => $file->get_product_id(),
      'download_id' => $file->get_download_id(),
      'expected_duration' => $response->expected_duration
    ) );

    $this->miguel_die( miguel_get_template( 'timer', array(
      'time' => $response->expected_duration + 10
    ) ) );
  }

  /**
   * @param string $content
   */
  public function miguel_die( $content ) {
    echo miguel_get_template( 'die', array(
      'content' => $content
    ) );
    die();
  }

  /**
   * @param WC_Order order
   * @param int download_id
   * @return WC_Order_Item_Product|null
   */
  protected function get_item($order, $download_id) {
    foreach ( $order->get_items() as $item_id => $item ) {
      if (!($item instanceof WC_Order_Item_Product)) continue;

      // Get the downloads for each item
      $downloads = $item->get_item_downloads();
      foreach ( $downloads as $download ) {
        // Check if the download ID matches the ID you are looking for
        if ( $download['id'] == $download_id ) {
          return $item;
        }
      }
    }
  }
}

return new Miguel_Download();
