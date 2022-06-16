<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * API client
 *
 * @package WC_Wosa
 */
class WC_Wosa_API {

  /**
   * @var string
   */
  protected $url;

  /**
   * @var string
   */
  protected $token;

  /**
   * @param string $url
   * @param string $token
   */
  public function __construct( $url = 'https://wosa.melvil.cz/v1/', $token ) {
    $this->url = $url;
    $this->token = $token;
  }

  /**
   * @return string
   */
  public function get_url() {
    return trailingslashit( $this->url );
  }

  /**
   * @return string
   */
  public function get_callback_url() {
    return add_query_arg( array(
      'action' => 'wc_wosa_process_notify'
    ), admin_url( 'admin-post.php' ) );
  }

  /**
   * @param string $book
   * @param string $format
   * @param array $args
   * @return array|WP_Error
   */
  public function generate( $book, $format, $args ) {
    if ( ! in_array( $format, array( 'epub', 'mobi', 'pdf', 'audio' ) ) ) {
      return new WP_Error( 'wosa', __( 'Format is not allowed.', 'wc-wosa' ) );
    }

    return $this->post( 'generate_' . $format . '/' . $book, $args );
  }

  /**
   * @param string $book
   * @param string $format
   * @param array $args
   * @return stdClass|array|WP_Error
   */
  public function generate_async( $book, $format, $args ) {
    $args['callback_url'] = $this->get_callback_url();

    wc_wosa_log( $args );

    $response = $this->post( 'generate_async_' . $format . '/' . $book, $args );
    if ( is_wp_error( $response ) ) {
      return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( ! $body ) {
      return new WP_Error( 'invalid-body', __( 'Invalid body response.', 'wc-wosa' ) );
    }

    $json = json_decode( $body );
    if ( ! $json ) {
      return new WP_Error( 'invalid-json', __( 'Invalid JSON.', 'wc-wosa' ) );
    }

    if ( property_exists( $json, 'error' ) ) {
      return new WP_Error( 'api-error', $json->reason );
    }

    return $json;
  }

  /**
   * @param string $query
   * @param array $body
   * @return array|WP_Error
   */
  private function post( $query, $body ) {
    $data = array(
      'method' => 'POST',
      'timeout' => 180,
      'headers' => array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $this->token
      ),
      'body' => json_encode( $body )
    );

    return wp_remote_post( $this->get_url() . $query, $data );
  }
}
