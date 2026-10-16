<?php
/**
 * Tests for Miguel_Download with the v2 client.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Download_V2 extends Miguel_Test_Case {

	public function test_serve_file_redirects_to_download_url(): void {
		$redirected = null;
		$client     = $this->createMock( Miguel_V2_Client::class );
		$client->method( 'get_watermarked_file' )->willReturn( array( 'downloadUrl' => 'https://dl/x' ) );

		$download = $this->create_service_with_mocks(
			'Miguel_Download',
			array(
				'client'           => $client,
				'redirect_handler' => function ( $url ) use ( &$redirected ) {
					$redirected = $url;
				},
				'error_handler'    => function ( $msg ) {
					$this->fail( 'Unexpected error: ' . $msg );
				},
			)
		);

		$order = Miguel_Helper_Order::create_order();
		$item  = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )->disableOriginalConstructor()->getMock();
		$file->method( 'get_name' )->willReturn( 'book-1' );
		$file->method( 'get_format' )->willReturn( 'epub' );

		$download->serve( $file, $order, $item );

		$this->assertSame( 'https://dl/x', $redirected );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function setUp(): void {
		parent::setUp();
		delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
	}

	private function reports() {
		return array_column( Miguel_Error_Reporter::get_buffer(), 'report' );
	}

	private function download_service( $file_factory ) {
		return $this->create_service_with_mocks(
			'Miguel_Download',
			array(
				'file_factory'     => $file_factory,
				'error_handler'    => function () {},
				'redirect_handler' => function ( $url ) {
					$this->fail( 'Unexpected redirect to ' . $url );
				},
			)
		);
	}

	private function file_mock( $valid ) {
		$file = $this->getMockBuilder( Miguel_File::class )->disableOriginalConstructor()->getMock();
		$file->method( 'is_valid' )->willReturn( $valid );
		$file->method( 'get_name' )->willReturn( 'book-1' );
		$file->method( 'get_format' )->willReturn( 'epub' );
		return $file;
	}

	public function test_invalid_miguel_file_is_reported(): void {
		$file     = $this->file_mock( false );
		$download = $this->download_service(
			function () use ( $file ) {
				return $file;
			}
		);

		$download->download( 'buyer@example.com', 'wc_order_key', 5, 0, 'dl-uuid', 77 );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'DOWNLOAD_FILE_INVALID', $reports[0]['code'] );
		$this->assertSame(
			array(
				'orderId'    => '77',
				'productId'  => '5',
				'downloadId' => 'dl-uuid',
			),
			$reports[0]['context']
		);
	}

	public function test_missing_order_is_reported(): void {
		$file     = $this->file_mock( true );
		$download = $this->download_service(
			function () use ( $file ) {
				return $file;
			}
		);

		$download->download( 'buyer@example.com', 'wc_order_key', 5, 0, 'dl-uuid', 999999 );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'DOWNLOAD_ORDER_NOT_FOUND', $reports[0]['code'] );
		$this->assertSame( '999999', $reports[0]['context']['orderId'] );
	}

	public function test_download_permission_of_a_deleted_order_is_served(): void {
		$permission = new WC_Customer_Download();
		$permission->set_order_id( 999999 );
		$permission->set_order_key( 'wc_order_deleted' );
		$permission->set_product_id( 5 );
		$permission->set_download_id( 'dl-uuid' );
		$permission->set_user_email( 'buyer@example.com' );
		$permission->set_access_granted( '2019-05-01 10:00:00' );
		$permission->save();

		$request = null;
		$client  = $this->createMock( Miguel_V2_Client::class );
		$client->method( 'get_watermarked_file' )->willReturnCallback(
			function ( $name, $req ) use ( &$request ) {
				$request = $req;
				return array( 'downloadUrl' => 'https://dl/x' );
			}
		);

		$redirected = null;
		$file       = $this->file_mock( true );
		$download   = $this->create_service_with_mocks(
			'Miguel_Download',
			array(
				'client'           => $client,
				'file_factory'     => function () use ( $file ) {
					return $file;
				},
				'error_handler'    => function ( $msg ) {
					$this->fail( 'Unexpected error: ' . $msg );
				},
				'redirect_handler' => function ( $url ) use ( &$redirected ) {
					$redirected = $url;
				},
			)
		);

		$download->download( 'buyer@example.com', 'wc_order_deleted', 5, 0, 'dl-uuid', 999999 );

		$this->assertSame( 'https://dl/x', $redirected );
		$this->assertSame( array(), $this->reports() );

		$arr = $request->to_array();
		$this->assertSame( 'buyer@example.com', $arr['userInfo']['email'] );
		$stored = new WC_Customer_Download( $permission->get_id() );
		$this->assertSame( $stored->get_access_granted()->format( DateTime::ATOM ), $arr['purchaseDate'] );
		$this->assertStringStartsWith( '2019-05-01', $arr['purchaseDate'] );
		$this->assertSame( '999999', $arr['orderInfo']['code'] );
		$this->assertSame( get_woocommerce_currency(), $arr['orderInfo']['currencyCode'] );
		$this->assertSame( 0.0, $arr['orderInfo']['soldPrice'] );

		$permission->delete();
	}

	public function test_download_from_unpaid_order_is_reported(): void {
		$order = Miguel_Helper_Order::create_order();
		$order->set_status( 'pending' );
		$order->set_date_paid( null );
		$order->save();
		$item     = array_values( $order->get_items() )[0];
		$download = $this->download_service( null );

		$download->serve( $this->file_mock( true ), $order, $item );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'DOWNLOAD_ORDER_NOT_PAID', $reports[0]['code'] );
		$this->assertSame( array( 'orderId' => (string) $order->get_id() ), $reports[0]['context'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_exception_in_download_is_reported_without_customer_data(): void {
		$download = $this->download_service(
			function () {
				throw new Exception( 'Unexpected failure' );
			}
		);

		$download->download( 'buyer@example.com', 'wc_order_key', 5, 0, 'dl-uuid', 77 );

		$reports = $this->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( 'DOWNLOAD_FAILED', $reports[0]['code'] );
		$this->assertSame( 'Unexpected failure', $reports[0]['message'] );
		$this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $reports[0] ) );
		$this->assertStringNotContainsString( 'wc_order_key', wp_json_encode( $reports[0] ) );
	}
}
