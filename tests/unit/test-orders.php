<?php
/**
 * Test Miguel Orders functionality
 *
 * @package Miguel\Tests
 */

class Test_Miguel_Orders extends Miguel_Test_Case {
	private function get_sut() {
		return $this->create_service_with_mocks( 'Miguel_Orders', [
			'api' => new Miguel_API( 'https://example.com/api/', 'test-token' ),
		] );
	}

	/**
	 * Test order data preparation
	 */
	public function test_prepare_order_data() {
		// Create a downloadable product with Miguel shortcode
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$sut = $this->get_sut();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $sut );
		$method = $reflection->getMethod( 'prepare_order_data' );
		$method->setAccessible( true );

		$result = $method->invoke( $sut, $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertArrayHasKey( 'user', $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'currency_code', $result );
		$this->assertArrayHasKey( 'purchase_date', $result );

		// Status and total_price were removed by user
		$this->assertArrayNotHasKey( 'status', $result );
		$this->assertArrayNotHasKey( 'total_price', $result );

		$this->assertEquals( strval( $order->get_id() ), $result['code'] );
		$this->assertIsArray( $result['products'] );
		$this->assertNotEmpty( $result['products'] );

		// Check product data
		$product_data = $result['products'][0];
		$this->assertArrayHasKey( 'code', $product_data );
		$this->assertEquals( 'dummy-name', $product_data['code'] );
		$this->assertArrayHasKey( 'price', $product_data );
		$this->assertEquals( 10.00, $product_data['price']['sold_without_vat'] );

		// Check user data structure
		$user_data = $result['user'];
		$this->assertArrayHasKey( 'id', $user_data );
		$this->assertArrayHasKey( 'email', $user_data );
		$this->assertArrayHasKey( 'full_name', $user_data );
		$this->assertArrayHasKey( 'address', $user_data );
		$this->assertArrayHasKey( 'lang', $user_data );
	}

	/**
	 * Test that multiple unique codes create separate product items
	 */
	public function test_prepare_order_data_multiple_codes() {
		// Create a downloadable product with multiple unique Miguel codes
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Set downloads directly via meta data to bypass WooCommerce validation
		$downloads = array(
			'book1_epub_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="epub"]',
			),
			'book1_pdf_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="pdf"]',
			),
			'book1_mobi_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="mobi"]',
			),
		);

		// Use helper method to set downloads bypassing validation
		Miguel_Helper_Product::set_product_downloads_bypass_validation( $product, $downloads );

		// Create a downloadable product with multiple unique Miguel codes
		$product2 = Miguel_Helper_Product::create_downloadable_product();

		// Set downloads directly via meta data to bypass WooCommerce validation
		$downloads2 = array(
			'book2_epub_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="epub"]',
			),
			'book2_pdf_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="pdf"]',
			),
			'book2_mobi_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="mobi"]',
			),
		);

		// Use helper method to set downloads bypassing validation
		Miguel_Helper_Product::set_product_downloads_bypass_validation( $product2, $downloads2 );

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->add_product( $product2, 1 );
		$order->save();

		$sut = $this->get_sut();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $sut );
		$method = $reflection->getMethod( 'prepare_order_data' );
		$method->setAccessible( true );

		$result = $method->invoke( $sut, $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );

		// Should have 4 separate product items, one for each unique code
		$this->assertCount( 2, $result['products'] );

		// Check that each product item has a string code (not array)
		$codes_found = array();
		foreach ( $result['products'] as $product_data ) {
			$this->assertArrayHasKey( 'code', $product_data );
			$this->assertIsString( $product_data['code'] );
			$this->assertArrayHasKey( 'price', $product_data );
			$this->assertArrayHasKey( 'sold_without_vat', $product_data['price'] );

			$codes_found[] = $product_data['code'];
		}

		// Verify we have all expected codes
		$expected_codes = array( 'book-1', 'book-2' );
		sort( $codes_found );
		sort( $expected_codes );
		$this->assertEquals( $expected_codes, $codes_found );
	}

	/**
	 * Test order deletion via status change
	 */
	public function test_sync_order_delete_on_status_change() {
		// Mock successful DELETE response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'DELETE' => array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			),
		));

		// Create order with Miguel product
		$order = Miguel_Helper_Order::create_order();
		$order->set_status('cancelled');
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'processing', 'cancelled', $order );

		// Verify DELETE request was made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, "Different number of requests: " . print_r( $requests, true ) );
		$this->assertEquals( 'DELETE', $requests[0]['method'] );
		$this->assertStringContains( 'orders/' . $order->get_id(), $requests[0]['url'] );
	}

	/**
	 * Test order sync via status change
	 */
	public function test_sync_order_post_on_status_change() {
		// Mock successful POST response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'POST' => array(
				'body' => '{"success": true}',
				'response' => array( 'code' => 201, 'message' => 'Created' ),
			),
		));

		// Create order with Miguel product
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		// Verify POST request was made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, "Different number of requests: " . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$this->assertStringContains( 'orders', $requests[0]['url'] );

		// Verify request body contains order data
		$body = json_decode( $requests[0]['body'], true );
		$this->assertArrayHasKey( 'code', $body );
		$this->assertEquals( strval( $order->get_id() ), $body['code'] );
		$this->assertArrayHasKey( 'products', $body );
	}

	/**
	 * Test order update handling
	 */
	public function test_handle_order_update() {
		// Mock successful POST response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'POST' => array(
				'body' => '{"success": true}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			),
		));

		// Create order with Miguel product
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// Trigger order update
		$this->get_sut()->handle_order_update( $order->get_id() );

		// Verify POST request was made to re-sync order
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, "Different number of requests: " . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$this->assertStringContains( 'orders', $requests[0]['url'] );
	}

	/**
	 * Test API delete_order method
	 */
	public function test_api_delete_order_success() {
		// Mock successful DELETE response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'DELETE' => array(
				'body' => '{"success": true}',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			),
		));

		$api = new Miguel_API( 'https://example.com/api/', 'test-token' );
		$result = $api->delete_order( '123' );

		// Should return the response array, not WP_Error
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertIsArray( $result );
		$this->assertEquals( 200, $result['response']['code'] );

		// Verify DELETE request was made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'DELETE', $requests[0]['method'] );
		$this->assertStringContains( 'orders/123', $requests[0]['url'] );
	}

	/**
	 * Test API delete_order method with 404 (acceptable)
	 */
	public function test_api_delete_order_404() {
		// Mock 404 response (order not found, which is acceptable)
		Miguel_Helper_HTTP::mock_api_responses(array(
			'DELETE' => array(
				'body' => '{"error": "Not found"}',
				'response' => array( 'code' => 404, 'message' => 'Not Found' ),
			),
		));

		$api = new Miguel_API( 'https://example.com/api/', 'test-token' );
		$result = $api->delete_order( '123' );

		// Should return the response array (404 is acceptable for DELETE)
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertIsArray( $result );
		$this->assertEquals( 404, $result['response']['code'] );
	}

	/**
	 * Test API delete_order method failure
	 */
	public function test_api_delete_order_failure() {
		// Mock server error response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'DELETE' => array(
				'body' => '{"error": "Server error"}',
				'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
			),
		));

		$api = new Miguel_API( 'https://example.com/api/', 'test-token' );
		$result = $api->delete_order( '123' );

		// Should return WP_Error for non-200/404 responses
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'miguel', $result->get_error_code() );
	}

	/**
	 * Test API submit_order method success
	 */
	public function test_api_submit_order_success() {
		// Mock successful POST response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'POST' => array(
				'body' => '{"success": true}',
				'response' => array( 'code' => 201, 'message' => 'Created' ),
			),
		));

		$api = new Miguel_API( 'https://example.com/api/', 'test-token' );
		$order_data = array(
			'code' => '123',
			'products' => array(),
		);
		$result = $api->submit_order( $order_data );

		// Should return the response array, not WP_Error
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertIsArray( $result );
		$this->assertEquals( 201, $result['response']['code'] );

		// Verify POST request was made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$this->assertStringContains( 'orders', $requests[0]['url'] );
	}

	/**
	 * Test API submit_order method failure
	 */
	public function test_api_submit_order_failure() {
		// Mock server error response
		Miguel_Helper_HTTP::mock_api_responses(array(
			'POST' => array(
				'body' => '{"error": "Validation failed"}',
				'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
			),
		));

		$api = new Miguel_API( 'https://example.com/api/', 'test-token' );
		$order_data = array(
			'code' => '123',
			'products' => array(),
		);
		$result = $api->submit_order( $order_data );

		// Should return WP_Error for non-200/201 responses
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'miguel', $result->get_error_code() );
	}

	/**
	 * Test that orders without Miguel products are ignored
	 */
	public function test_sync_order_ignores_non_miguel_orders() {
		// Mock API responses (should not be called)
		Miguel_Helper_HTTP::mock_api_responses(array());

		// Create order with regular (non-Miguel) product
		$product = Miguel_Helper_Product::create_virtual_product();
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$sut = $this->get_sut();

		// Trigger sync - should be ignored
		$sut->sync_order( $order->get_id(), 'pending', 'processing', $order );

		// Verify no API requests were made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );
	}

	/**
	 * Test order update with non-Miguel order is ignored
	 */
	public function test_handle_order_update_ignores_non_miguel_orders() {
		// Mock API responses (should not be called)
		Miguel_Helper_HTTP::mock_api_responses(array());

		// Create order with regular (non-Miguel) product
		$product = Miguel_Helper_Product::create_virtual_product();
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$sut = $this->get_sut();

		// Trigger order update - should be ignored
		$sut->handle_order_update( $order->get_id() );

		// Verify no API requests were made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );
	}

	/**
	 * Test handle_order_update with invalid order ID
	 */
	public function test_handle_order_update_invalid_id() {
		// Mock API responses (should not be called)
		Miguel_Helper_HTTP::mock_api_responses(array());

		$sut = $this->get_sut();

		// This should not cause errors and should not make API calls
		$sut->handle_order_update( 99999 );

		// Verify no API requests were made
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );
	}

	/**
	 * Test all deletion status changes
	 */
	public function test_sync_order_all_deletion_statuses() {


		// Test all statuses that should trigger deletion
		$deletion_statuses = array( 'refunded', 'cancelled', 'failed', 'trash' );

		foreach ( $deletion_statuses as $status ) {
			// Mock successful DELETE response for each iteration
			Miguel_Helper_HTTP::mock_api_responses(array(
				'DELETE' => array(
					'body' => '{"success": true}',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				),
			));

			// Create order with Miguel product
			$product = Miguel_Helper_Product::create_downloadable_product();
			$order = Miguel_Helper_Order::create_order();
			$order->add_product( $product, 1 );
			$order->save();

			$order->set_status( $status );
			$this->get_sut()->sync_order( $order->get_id(), 'processing', $status, $order );

			// Verify DELETE request was made
			$requests = Miguel_Helper_HTTP::get_requests();
			$this->assertCount( 1, $requests, "Failed for status: $status" );
			$this->assertEquals( 'DELETE', $requests[0]['method'], "Failed for status: $status" );

			// Clear for next iteration - this will be handled by tearDown() but we need it for the loop
			Miguel_Helper_HTTP::clear();

			// Delete order
			$order->delete();
		}
	}
}
