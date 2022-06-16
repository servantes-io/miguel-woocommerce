<?php
/**
 * Mock HTTP response
 *
 * @package WC_Wosa\Tests
 */
class WC_Wosa_Helper_HTTP {

  /**
   * @var string
   */
  private static $what;

  /**
   * @param string $what
   */
  public static function mock_server_response( $what ) {
    self::$what = $what;

    add_filter( 'pre_http_request', array( __CLASS__, 'response' ), 10, 3 ); 
  }

  /**
   * @param false $response
   * @param array $args
   * @param string $url
   * @return array
   */
  public static function response( $response, $args, $url ) {
    switch( self::$what ) {
      case '__return__url':
        $response = $url;
        break;  
      case '__return__headers':
        $response = $args['headers'];
        break;
      case '__return__body':
        $response = $args['body'];
        break;  
    }

    return array(
      'body' => $response,
      'response' => array( 'code' => 200 )
    );
  }

  /**
   * Remove filter.
   */
  public static function clear() {
    remove_filter( 'pre_http_request', array( __CLASS__, 'response' ) );
  }
}
