<?php
/**
 * Test API
 *
 * @package WC_Wosa\Tests
 */
class WC_Wosa_Test_API extends WP_UnitTestCase {

  /**
   * Setup.
   */
  public function setUp() {
    parent::setUp();

    $this->token = '1a2b3c4d5e6f7g8h9';
    $this->wosa = new WC_Wosa_API( $this->token );
  }

  /**
   * Test get_url().
   */
  public function test_get_url() {
    $this->assertEquals( 'https://wosa.melvil.cz/v1/', $this->wosa->get_url() ); 
  }

  /**
   * Test generate(), request url.
   */
  public function test_generate__url() {
    WC_Wosa_Helper_Http::mock_server_response( '__return__url' );
  
    $response = $this->wosa->generate( 'dummy-book', 'epub', array() );

    $this->assertEquals( 'https://wosa.melvil.cz/v1/generate_epub/dummy-book', $response['body'] );

    WC_Wosa_Helper_Http::clear();
  }

  /**
   * Test generate(), request headers.
   */
  public function test_generate__headers() {
    WC_Wosa_Helper_Http::mock_server_response( '__return__headers' );

    $response = $this->wosa->generate( 'dummy-book', 'epub', array() );

    $want = array(
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $this->token
    );

    $this->assertEquals( $want, $response['body'] );

    WC_Wosa_Helper_Http::clear();
  }

  /**
   * Test generate(), invalid format.
   */
  public function test_generate__format() {
    $response = $this->wosa->generate( 'dummy-book', 'doc', null );

    $this->assertEquals( true, is_wp_error( $response ) ); 
    $this->assertEquals( __( 'Format is not allowed.', 'wc-melvil' ), $response->get_error_message() ); 
  }
}
