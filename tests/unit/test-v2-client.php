<?php
/**
 * Tests for Miguel_V2_Client.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_V2_Client extends WP_UnitTestCase {

	private $token = 'tok123';
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->sut = new Miguel_V2_Client( 'https://miguel.servantes.cz', $this->token );
	}

	public function tearDown(): void {
		Miguel_Helper_HTTP::clear();
		parent::tearDown();
	}

	private function watermark_request() {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		return new Miguel_V2_Watermarked_File_Request( 'epub', $user, '2023-01-15T10:00:00+00:00', '1', 'CZK', 10.0 );
	}

	public function test_get_watermarked_file_url_body_headers(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => wp_json_encode( array( 'downloadUrl' => 'https://dl/x', 'downloadExpiresAt' => '2023-01-16T00:00:00+00:00' ) ),
					'response' => array( 'code' => 200 ),
				),
			)
		);

		$result = $this->sut->get_watermarked_file( 'book-1', $this->watermark_request() );

		$this->assertSame( 'https://dl/x', $result['downloadUrl'] );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/product-variants/book-1/watermarked-file', $req['url'] );
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'Bearer ' . $this->token, $req['headers']['Authorization'] );

		$body = json_decode( $req['body'], true );
		$this->assertSame( 'epub', $body['target'] );
	}

	public function test_get_watermarked_file_rejects_disallowed_format(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$req  = new Miguel_V2_Watermarked_File_Request( 'doc', $user, '2023-01-15T10:00:00+00:00', '1', 'CZK', 10.0 );

		$result = $this->sut->get_watermarked_file( 'book-1', $req );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( __( 'Format is not allowed.', 'miguel' ), $result->get_error_message() );
	}

	public function test_create_order_accepts_201(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 201 ) ) )
		);

		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$order = new Miguel_V2_Order_Create( '1', $user, null, 'CZK', array(), 'disable', '1', null, null );

		$this->assertTrue( $this->sut->create_order( $order ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/orders', $req['url'] );
	}

	public function test_create_order_parses_problem_on_409(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => wp_json_encode( array( 'status' => 409, 'title' => 'Conflict', 'detail' => 'Duplicate' ) ),
					'response' => array( 'code' => 409 ),
				),
			)
		);

		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$order = new Miguel_V2_Order_Create( '1', $user, null, 'CZK', array(), 'disable', '1', null, null );

		$result = $this->sut->create_order( $order );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringContainsString( 'Conflict', $result->get_error_message() );
	}

	public function test_delete_order_url_and_204(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '', 'response' => array( 'code' => 204 ) ) )
		);

		$this->assertTrue( $this->sut->delete_order( '123' ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/orders/123', $req['url'] );
		$this->assertSame( 'DELETE', $req['method'] );
	}

	public function test_delete_order_treats_404_as_success(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '', 'response' => array( 'code' => 404 ) ) )
		);

		$this->assertTrue( $this->sut->delete_order( '123' ) );
	}

	public function test_connect_posts_to_woocommerce_endpoint(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 200 ) ) )
		);

		$req_dto = new Miguel_V2_Connect_Request( '8.0.0', '1.6.3', 'https://shop.cz/', '/' );
		$this->assertTrue( $this->sut->connect( $req_dto ) );

		$req = Miguel_Helper_HTTP::get_last_request();
		$this->assertSame( 'https://miguel.servantes.cz/v2/eshop/woocommerce/connect', $req['url'] );
	}
}
