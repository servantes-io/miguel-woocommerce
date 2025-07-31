<?php
/**
 * Test API
 *
 * @package Miguel\Tests\Services
 */

use Servantes\Miguel\Services\API;

class APITest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->token = '1a2b3c4d5e6f7g8h9';
		$this->sut = new API( 'https://miguel.servantes.cz/v1/', $this->token );
	}

	/**
	 * Test get_url().
	 */
	public function test_get_url(): void {
		$this->assertEquals( 'https://miguel.servantes.cz/v1/', $this->sut->get_url() );
	}

	/**
	 * Data provider for test_generate__url().
	 */
	public function data_provider_test_generate__url() {
		return array(
			array( 'dummy-book', 'https://miguel.servantes.cz/v1/generate_epub/dummy-book' ),
			array( 'dummy/book', 'https://miguel.servantes.cz/v1/generate_epub/dummy%2Fbook' ),
		);
	}

	/**
	 * Test generate(), request url.
	 *
	 * @dataProvider data_provider_test_generate__url
	 */
	public function test_generate__url( $id, $url ): void {
		Miguel_Helper_Http::mock_server_response( '__return__url' );

		$response = $this->sut->generate( $id, 'epub', array() );

		$this->assertEquals( $url, $response['body'] );

		Miguel_Helper_Http::clear();
	}

	/**
	 * Test generate(), request headers.
	 */
	public function test_generate__headers(): void {
		Miguel_Helper_Http::mock_server_response( '__return__headers' );

		$response = $this->sut->generate( 'dummy-book', 'epub', array() );

		$want = array(
			'Content-Type' => 'application/json; charset=utf-8',
			'Authorization' => 'Bearer ' . $this->token,
			'Accept-Language' => 'en_US',
		);

		$this->assertEquals( $want, $response['body'] );

		Miguel_Helper_Http::clear();
	}

	/**
	 * Test generate(), invalid format.
	 */
	public function test_generate__format(): void {
		$response = $this->sut->generate( 'dummy-book', 'doc', null );

		$this->assertEquals( true, is_wp_error( $response ) );
		$this->assertEquals( __( 'Format is not allowed.', 'miguel' ), $response->get_error_message() );
	}
}
