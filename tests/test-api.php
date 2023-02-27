<?php
/**
 * Test API
 *
 * @package Miguel\Tests
 */
class Miguel_Test_API extends WP_UnitTestCase {

  /**
   * Setup.
   */
  public function setUp() {
    parent::setUp();

    $this->token = '1a2b3c4d5e6f7g8h9';
    $this->miguel = new Miguel_API( $this->token );
  }

  /**
   * Test get_url().
   */
  public function test_get_url() {
    $this->assertEquals( 'https://miguel.servantes.cz/v1/', $this->miguel->get_url() );
  }

  /**
   * Test generate(), request url.
   */
  public function test_generate__url() {
    Miguel_Helper_Http::mock_server_response( '__return__url' );

    $response = $this->miguel->generate( 'dummy-book', 'epub', array() );

    $this->assertEquals( 'https://miguel.servantes.cz/v1/generate_epub/dummy-book', $response['body'] );

    Miguel_Helper_Http::clear();
  }

  /**
   * Test generate(), request headers.
   */
  public function test_generate__headers() {
    Miguel_Helper_Http::mock_server_response( '__return__headers' );

    $response = $this->miguel->generate( 'dummy-book', 'epub', array() );

    $want = array(
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $this->token
    );

    $this->assertEquals( $want, $response['body'] );

    Miguel_Helper_Http::clear();
  }

  /**
   * Test generate(), invalid format.
   */
  public function test_generate__format() {
    $response = $this->miguel->generate( 'dummy-book', 'doc', null );

    $this->assertEquals( true, is_wp_error( $response ) );
    $this->assertEquals( __( 'Format is not allowed.', 'miguel' ), $response->get_error_message() );
  }
}
