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
}
