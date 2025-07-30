<?php
/**
 * Improved tests for Miguel Download functionality with dependency injection
 *
 * @package Miguel\Tests
 */

class Test_Miguel_Download_Improved extends Miguel_Test_Case {

	/**
	 * Test that hooks are registered correctly
	 */
	public function test_download_registers_correct_hooks() {
		$download = $this->create_service_with_mocks( 'Miguel_Download' );
		$download->register_hooks();

		$hook_manager     = $download->get_hook_manager();
		$registered_hooks = $hook_manager->get_registered_hooks();

		$this->assertCount( 1, $registered_hooks );
		$this->assertEquals( 'woocommerce_download_product', $registered_hooks[0]['hook'] );
		$this->assertEquals( 10, $registered_hooks[0]['priority'] );
		$this->assertEquals( 6, $registered_hooks[0]['accepted_args'] );
	}

	/**
	 * Test download with invalid file
	 */
	public function test_download_with_invalid_file() {
		$error_messages = [];
		$error_handler  = function( $message ) use ( &$error_messages ) {
			$error_messages[] = $message;
		};

		$file_factory = function( $product_id, $download_id ) {
			return new WP_Error( 'invalid_file', 'File not found' );
		};

		$download = $this->create_service_with_mocks( 'Miguel_Download', [
			'file_factory'  => $file_factory,
			'error_handler' => $error_handler,
		] );

		// This should return early without error since file is WP_Error
		$download->download( 'test@example.com', 'order_key', 123, 1, 456, 789 );

		$this->assertEmpty( $error_messages );
	}

	/**
	 * Test download with invalid shortcode params
	 */
	public function test_download_with_invalid_shortcode_params() {
		$error_messages = [];
		$error_handler  = function( $message ) use ( &$error_messages ) {
			$error_messages[] = $message;
		};

		$file_mock = $this->createMock( Miguel_File::class );
		$file_mock->method( 'is_valid' )->willReturn( false );

		$file_factory = function( $product_id, $download_id ) use ( $file_mock ) {
			return $file_mock;
		};

		$download = $this->create_service_with_mocks( 'Miguel_Download', [
			'file_factory'  => $file_factory,
			'error_handler' => $error_handler,
		] );

		$download->download( 'test@example.com', 'order_key', 123, 1, 456, 789 );

		$this->assertCount( 1, $error_messages );
		$this->assertStringContains( 'Invalid shortcode params', $error_messages[0] );
	}

	/**
	 * Test successful file serving with redirect
	 */
	public function test_successful_file_serving_with_redirect() {
		$redirected_urls = [];
		$redirect_handler = function( $url ) use ( &$redirected_urls ) {
			$redirected_urls[] = $url;
		};

		// Mock API response with download URL
		$api_mock = $this->createMock( Miguel_API::class );
		$api_response = $this->create_mock_api_response([
			'download_url' => 'https://example.com/download/file.pdf'
		]);
		$api_mock->method( 'generate' )->willReturn( $api_response );

		// Mock file
		$file_mock = $this->createMock( Miguel_File::class );
		$file_mock->method( 'is_valid' )->willReturn( true );
		$file_mock->method( 'get_name' )->willReturn( 'test-file' );
		$file_mock->method( 'get_format' )->willReturn( 'pdf' );

		$file_factory = function( $product_id, $download_id ) use ( $file_mock ) {
			return $file_mock;
		};

		// Mock request
		$request_mock = $this->createMock( Miguel_Request::class );
		$request_mock->method( 'is_valid' )->willReturn( true );
		$request_mock->method( 'to_array' )->willReturn( [ 'test' => 'data' ] );

		// Create order and item
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->save();

		$download = $this->create_service_with_mocks( 'Miguel_Download', [
			'api'              => $api_mock,
			'file_factory'     => $file_factory,
			'redirect_handler' => $redirect_handler,
		] );

		// For this test, let's directly test serve_file method
		$request_mock = $this->createMock( Miguel_Request::class );
		$request_mock->method( 'to_array' )->willReturn( [ 'test' => 'data' ] );

		$download->serve_file( $file_mock, $request_mock );

		$this->assertCount( 1, $redirected_urls );
		$this->assertEquals( 'https://example.com/download/file.pdf', $redirected_urls[0] );
	}

	/**
	 * Test API error handling
	 */
	public function test_api_error_handling() {
		$error_messages = [];
		$error_handler  = function( $message ) use ( &$error_messages ) {
			$error_messages[] = $message;
		};

		// Mock API to return error
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->method( 'generate' )->willReturn( new WP_Error( 'api_error', 'API connection failed' ) );

		// Mock file
		$file_mock = $this->createMock( Miguel_File::class );
		$file_mock->method( 'get_name' )->willReturn( 'test-file' );
		$file_mock->method( 'get_format' )->willReturn( 'pdf' );

		// Mock request
		$request_mock = $this->createMock( Miguel_Request::class );
		$request_mock->method( 'to_array' )->willReturn( [ 'test' => 'data' ] );

		$download = $this->create_service_with_mocks( 'Miguel_Download', [
			'api'           => $api_mock,
			'error_handler' => $error_handler,
		] );

		$download->serve_file( $file_mock, $request_mock );

		$this->assertCount( 1, $error_messages );
		$this->assertStringContains( 'API connection failed', $error_messages[0] );
	}

	/**
	 * Test JSON parsing error
	 */
	public function test_json_parsing_error() {
		$error_messages = [];
		$error_handler  = function( $message ) use ( &$error_messages ) {
			$error_messages[] = $message;
		};

		// Mock API to return invalid JSON
		$api_mock = $this->createMock( Miguel_API::class );
		$api_response = [
			'body'     => 'invalid json content',
			'response' => [ 'code' => 200 ],
		];
		$api_mock->method( 'generate' )->willReturn( $api_response );

		// Mock file
		$file_mock = $this->createMock( Miguel_File::class );
		$file_mock->method( 'get_name' )->willReturn( 'test-file' );
		$file_mock->method( 'get_format' )->willReturn( 'pdf' );

		// Mock request
		$request_mock = $this->createMock( Miguel_Request::class );
		$request_mock->method( 'to_array' )->willReturn( [ 'test' => 'data' ] );

		$download = $this->create_service_with_mocks( 'Miguel_Download', [
			'api'           => $api_mock,
			'error_handler' => $error_handler,
		] );

		$download->serve_file( $file_mock, $request_mock );

		$this->assertCount( 1, $error_messages );
		$this->assertStringContains( 'Something went wrong', $error_messages[0] );
	}

	/**
	 * Test backward compatibility when no hook manager is provided
	 */
	public function test_backward_compatibility_without_hook_manager() {
		$download = new Miguel_Download();
		$download->register_hooks();

		// Verify that the hook was registered using WordPress directly
		$this->assertTrue( has_action( 'woocommerce_download_product', [ $download, 'download' ] ) );

		// Clean up
		remove_action( 'woocommerce_download_product', [ $download, 'download' ], 10 );
	}
}
