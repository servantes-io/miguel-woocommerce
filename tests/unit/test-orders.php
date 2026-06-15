<?php
/**
 * Test Miguel Orders functionality
 *
 * @package Miguel\Tests
 */

class Test_Miguel_Orders extends Miguel_Test_Case {
	private function get_sut() {
		return $this->create_service_with_mocks(
			'Miguel_Orders',
			array(
				'client' => new Miguel_V2_Client( 'https://example.com', 'test-token' ),
			)
		);
	}

	/**
	 * Test order deletion via status change
	 */
	public function test_sync_order_delete_on_status_change() {
		// Mock successful DELETE response.
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'DELETE' => array(
					'body'     => '',
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				),
			)
		);

		// Create order with Miguel product.
		$order = Miguel_Helper_Order::create_order();
		$order->set_status( 'cancelled' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'processing', 'cancelled', $order );

		// Verify DELETE request was made.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'DELETE', $requests[0]['method'] );
		$this->assertStringContains( '/v2/orders/' . $order->get_id(), $requests[0]['url'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test order sync via status change
	 */
	public function test_sync_order_post_on_status_change() {
		// Mock successful POST response.
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		// Create order with Miguel product.
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		// Verify POST request was made.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$this->assertStringContains( '/v2/orders', $requests[0]['url'] );

		// Verify request body contains order data.
		$body = json_decode( $requests[0]['body'], true );
		$this->assertArrayHasKey( 'code', $body );
		$this->assertEquals( strval( $order->get_id() ), $body['code'] );
		$this->assertArrayHasKey( 'items', $body );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test order update handling
	 */
	public function test_handle_order_update() {
		// Mock successful POST response.
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				),
			)
		);

		// Create order with Miguel product.
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		// Trigger order update.
		$this->get_sut()->handle_order_update( $order->get_id() );

		// Verify POST request was made to re-sync order.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$this->assertStringContains( '/v2/orders', $requests[0]['url'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test that orders without Miguel products are ignored
	 */
	public function test_sync_order_ignores_non_miguel_orders() {
		// Mock API responses (should not be called).
		Miguel_Helper_HTTP::mock_api_responses( array() );

		// Create order with regular (non-Miguel) product.
		$product = Miguel_Helper_Product::create_virtual_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$sut = $this->get_sut();

		// Trigger sync - should be ignored.
		$sut->sync_order( $order->get_id(), 'pending', 'processing', $order );

		// Verify no API requests were made.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test order update with non-Miguel order is ignored
	 */
	public function test_handle_order_update_ignores_non_miguel_orders() {
		// Mock API responses (should not be called).
		Miguel_Helper_HTTP::mock_api_responses( array() );

		// Create order with regular (non-Miguel) product.
		$product = Miguel_Helper_Product::create_virtual_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$sut = $this->get_sut();

		// Trigger order update - should be ignored.
		$sut->handle_order_update( $order->get_id() );

		// Verify no API requests were made.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test handle_order_update with invalid order ID
	 */
	public function test_handle_order_update_invalid_id() {
		// Mock API responses (should not be called).
		Miguel_Helper_HTTP::mock_api_responses( array() );

		$sut = $this->get_sut();

		// This should not cause errors and should not make API calls.
		$sut->handle_order_update( 99999 );

		// Verify no API requests were made.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 0, $requests );
	}

	/**
	 * Test all deletion status changes
	 */
	public function test_sync_order_all_deletion_statuses() {
		// Test all statuses that should trigger deletion.
		$deletion_statuses = array( 'refunded', 'cancelled', 'failed', 'trash' );

		foreach ( $deletion_statuses as $status ) {
			// Mock successful DELETE response for each iteration.
			Miguel_Helper_HTTP::mock_api_responses(
				array(
					'DELETE' => array(
						'body'     => '',
						'response' => array( 'code' => 204, 'message' => 'No Content' ),
					),
				)
			);

			// Create order with Miguel product.
			$product = Miguel_Helper_Product::create_downloadable_product();
			$order   = Miguel_Helper_Order::create_order();
			$order->add_product( $product, 1 );
			$order->save();

			$order->set_status( $status );
			$this->get_sut()->sync_order( $order->get_id(), 'processing', $status, $order );

			// Verify DELETE request was made.
			$requests = Miguel_Helper_HTTP::get_requests();
			$this->assertCount( 1, $requests, "Failed for status: $status" );
			$this->assertEquals( 'DELETE', $requests[0]['method'], "Failed for status: $status" );
			$this->assertStringContains( '/v2/orders/' . $order->get_id(), $requests[0]['url'], "Failed for status: $status" );

			// Clear for next iteration - this will be handled by tearDown() but we need it for the loop.
			Miguel_Helper_HTTP::clear();

			// Delete order.
			$order->delete();
		}
	}
}
