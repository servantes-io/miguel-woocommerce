<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * API client
 *
 * @package Miguel
 */
class Miguel_API {

  /**
   * URL
   * @var string
   */
  protected $url;

  /**
   * Token
   * @var string
   */
  protected $token;

  /**
   * Constructor
   * @param string $url
   * @param string $token
   */
  public function __construct( $url, $token ) {
    $this->url = $url;
    $this->token = $token;
  }

  /**
   * Get URL
   * @return string
   */
  public function get_url() {
    return trailingslashit( $this->url );
  }

  /**
   * Get callback URL
   * @return string
   */
  public function get_callback_url() {
    return add_query_arg( array(
      'action' => 'miguel_process_notify'
    ), admin_url( 'admin-post.php' ) );
  }

  /**
   * Generate
   * @param string $book
   * @param string $format
   * @param array $args
   * @return array|WP_Error
   */
  public function generate( $book, $format, $args ) {
    if ( ! in_array( $format, array( 'epub', 'mobi', 'pdf', 'audio' ) ) ) {
      return new WP_Error( 'miguel', __( 'Format is not allowed.', 'miguel' ) );
    }

    return $this->post( 'generate_' . $format . '/' . urlencode($book), $args );
  }

  /**
   * Generate async
   * @param string $book
   * @param string $format
   * @param array $args
   * @return stdClass|array|WP_Error
   */
  public function generate_async( $book, $format, $args ) {
    $args['callback_url'] = $this->get_callback_url();

    miguel_log( $args );

    $response = $this->post( 'generate_async_' . $format . '/' . $book, $args );
    if ( is_wp_error( $response ) ) {
      return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( ! $body ) {
      return new WP_Error( 'invalid-body', __( 'Invalid body response.', 'miguel' ) );
    }

    $json = json_decode( $body );
    if ( ! $json ) {
      return new WP_Error( 'invalid-json', __( 'Invalid JSON.', 'miguel' ) );
    }

    if ( property_exists( $json, 'error' ) ) {
      return new WP_Error( 'api-error', $json->reason );
    }

    return $json;
  }

  /**
   * Get
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
        'Authorization' => 'Bearer ' . $this->token,
        'Accept-Language' => get_user_locale(),
      ),
      'body' => json_encode( $body )
    );

    return wp_remote_post( $this->get_url() . $query, $data );
  }
}
