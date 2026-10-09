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
		delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
	}

	public function tearDown(): void {
		Miguel_Helper_HTTP::clear();
		wp_clear_scheduled_hook( Miguel_Error_Reporter::FLUSH_SOON_HOOK );
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

	private function reports() {
		return array_column( Miguel_Error_Reporter::get_buffer(), 'report' );
	}

	private function order_dto() {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		return new Miguel_V2_Order_Create( '17', $user, null, 'CZK', array(), 'disable', '17', null, null );
	}

	public function test_network_failure_is_reported_as_unreachable(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) )
		);

		$this->assertTrue( is_wp_error( $this->sut->create_order( $this->order_dto() ) ) );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'MIGUEL_UNREACHABLE', $reports[0]['code'] );
		$this->assertSame( 'cURL error 6: Could not resolve host', $reports[0]['message'] );
		$this->assertSame( 'POST /v2/orders', $reports[0]['operation'] );
		$this->assertSame( array( 'orderId' => '17' ), $reports[0]['context'] );
		$this->assertArrayNotHasKey( 'httpStatus', $reports[0] );
	}

	public function test_rejected_key_is_reported_as_auth_rejected(): void {
		foreach ( array( 401, 403 ) as $status ) {
			delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
			Miguel_Helper_HTTP::mock_api_responses(
				array( 'POST' => array( 'body' => '{"title":"Unauthorized"}', 'response' => array( 'code' => $status ) ) )
			);

			$req_dto = new Miguel_V2_Connect_Request( '8.0.0', '1.6.3', 'https://shop.cz/', '/' );
			$this->assertTrue( is_wp_error( $this->sut->connect( $req_dto ) ) );

			$reports = $this->reports();
			$this->assertCount( 1, $reports );
			$this->assertSame( 'MIGUEL_AUTH_REJECTED', $reports[0]['code'] );
			$this->assertSame( $status, $reports[0]['httpStatus'] );
			$this->assertSame( 'POST /v2/eshop/woocommerce/connect', $reports[0]['operation'] );
			$this->assertSame( '{"title":"Unauthorized"}', $reports[0]['responseExcerpt'] );
		}
	}

	public function test_other_error_status_is_reported_as_http_error(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '{"title":"Boom"}', 'response' => array( 'code' => 500 ) ) )
		);

		$this->assertTrue( is_wp_error( $this->sut->delete_order( '123' ) ) );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'MIGUEL_HTTP_ERROR', $reports[0]['code'] );
		$this->assertSame( 500, $reports[0]['httpStatus'] );
		$this->assertSame( 'DELETE /v2/orders/123', $reports[0]['operation'] );
		$this->assertSame( array( 'orderId' => '123' ), $reports[0]['context'] );
	}

	public function test_delete_404_is_not_reported(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'DELETE' => array( 'body' => '', 'response' => array( 'code' => 404 ) ) )
		);

		$this->sut->delete_order( '123' );

		$this->assertCount( 0, $this->reports() );
	}

	public function test_unparsable_watermark_response_is_reported(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '<html>proxy error</html>', 'response' => array( 'code' => 200 ) ) )
		);

		$this->assertTrue( is_wp_error( $this->sut->get_watermarked_file( 'book-1', $this->watermark_request() ) ) );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'MIGUEL_RESPONSE_UNPARSABLE', $reports[0]['code'] );
		$this->assertSame( 200, $reports[0]['httpStatus'] );
		$this->assertSame( 'POST /v2/product-variants/book-1/watermarked-file', $reports[0]['operation'] );
		$this->assertSame( '<html>proxy error</html>', $reports[0]['responseExcerpt'] );
	}

	public function test_watermark_response_without_download_url_is_reported_as_invalid(): void {
		foreach ( array( '{"task":null}', '{"downloadUrl":42}', '"text"' ) as $body ) {
			delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
			Miguel_Helper_HTTP::mock_api_responses(
				array( 'POST' => array( 'body' => $body, 'response' => array( 'code' => 200 ) ) )
			);

			$this->sut->get_watermarked_file( 'book-1', $this->watermark_request() );

			$reports = $this->reports();
			$this->assertCount( 1, $reports, $body );
			$this->assertSame( 'MIGUEL_RESPONSE_INVALID', $reports[0]['code'], $body );
		}
	}

	public function test_missing_configuration_is_not_reported(): void {
		$sut = new Miguel_V2_Client( 'https://miguel.servantes.cz', '' );

		$this->assertTrue( is_wp_error( $sut->create_order( $this->order_dto() ) ) );

		$this->assertCount( 0, $this->reports() );
	}

	public function test_successful_call_schedules_a_flush_of_buffered_reports(): void {
		Miguel_Helper_HTTP::mock_api_responses(
			array( 'POST' => array( 'body' => '{}', 'response' => array( 'code' => 201 ) ) )
		);

		$this->sut->create_order( $this->order_dto() );
		$this->assertFalse( wp_next_scheduled( Miguel_Error_Reporter::FLUSH_SOON_HOOK ) );

		Miguel_Error_Reporter::report( 'TEST_CODE', 'm' );
		$this->sut->create_order( $this->order_dto() );

		$this->assertNotFalse( wp_next_scheduled( Miguel_Error_Reporter::FLUSH_SOON_HOOK ) );
		$this->assertCount( 1, $this->reports() );
	}
}
