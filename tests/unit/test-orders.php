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

	/**
	 * Test that the dedupe hash is NOT stored when the create-order request fails.
	 */
	public function test_sync_order_does_not_store_hash_on_failure() {
		// Mock failing POST response (5xx).
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{"title":"Server error"}',
					'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
				),
			)
		);

		// Create paid order with Miguel product.
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		// Verify the POST request was attempted.
		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );

		// Verify the dedupe hash was NOT stored on failure.
		$this->assertEmpty( $order->get_meta( '_miguel_last_sync_hash', true ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test that a second sync of an unchanged order makes no additional HTTP request.
	 */
	public function test_sync_order_dedupes_unchanged_order() {
		// Mock successful POST response.
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		// Create paid order with Miguel product.
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$sut = $this->get_sut();

		// First sync should make one POST request and store the dedupe hash.
		$sut->sync_order( $order->get_id(), 'new', 'processing', $order );
		$count_after_first = count( Miguel_Helper_HTTP::get_requests() );
		$this->assertEquals( 1, $count_after_first, 'First sync should make exactly one request.' );
		$this->assertNotEmpty( $order->get_meta( '_miguel_last_sync_hash', true ) );

		// Second sync of the same unchanged order (same in-memory instance) should be deduped.
		$sut->sync_order( $order->get_id(), 'new', 'processing', $order );
		$count_after_second = count( Miguel_Helper_HTTP::get_requests() );
		$this->assertEquals( $count_after_first, $count_after_second, 'Second sync should not make an additional request.' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * When the setting is enabled, the synced order carries sendEmail = "auto".
	 */
	public function test_sync_order_sends_auto_email_flag_when_setting_enabled() {
		update_option( Miguel_Orders::SEND_EMAIL_OPTION, 'yes' );

		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'auto', $body['sendEmail'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * With the setting unset (default), the synced order carries sendEmail = "disable".
	 */
	public function test_sync_order_sends_disable_email_flag_by_default() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$this->get_sut()->sync_order( $order->get_id(), 'new', 'processing', $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'disable', $body['sendEmail'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * The miguel_suppress_order_sync filter must prevent the sync from being queued.
	 *
	 * This is what stops orders created via the Miguel order create API from being
	 * synced straight back to Miguel (which would create a duplicate order).
	 */
	public function test_queue_order_sync_is_suppressed_by_filter() {
		$order = Miguel_Helper_Order::create_order();
		$sut   = $this->get_sut();

		$args = array(
			'order_id'   => $order->get_id(),
			'from_state' => 'pending',
			'to_state'   => 'processing',
		);

		// With the suppression filter active, no async sync action is queued.
		add_filter( 'miguel_suppress_order_sync', '__return_true' );
		$sut->queue_order_sync( $order->get_id(), 'pending', 'processing', $order );
		remove_filter( 'miguel_suppress_order_sync', '__return_true' );

		$this->assertFalse(
			as_has_scheduled_action( Miguel_Orders::ASYNC_SYNC_ACTION, $args, 'miguel' ),
			'Suppression filter must prevent the order sync from being queued.'
		);

		// Without the filter, the sync action is queued as usual.
		$sut->queue_order_sync( $order->get_id(), 'pending', 'processing', $order );

		$this->assertTrue(
			as_has_scheduled_action( Miguel_Orders::ASYNC_SYNC_ACTION, $args, 'miguel' ),
			'Without suppression, the order sync must be queued.'
		);

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * The statuses that mean "the customer no longer has this order" are operator-configurable.
	 * Default keeps the behaviour the plugin has always had.
	 */
	public function test_deleted_statuses_default_to_refunded_cancelled_failed() {
		delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );

		$this->assertSame(
			array( 'refunded', 'cancelled', 'failed' ),
			Miguel_Orders::get_deleted_order_statuses() );
	}

	public function test_deleted_statuses_follow_the_setting() {
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array( 'refunded' ) );

		try {
			$this->assertSame( array( 'refunded' ), Miguel_Orders::get_deleted_order_statuses() );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	/**
	 * An empty setting means "never delete", not "fall back to the default" — an operator who
	 * unticks everything is asking for orders never to be removed from Miguel.
	 */
	public function test_deleted_statuses_can_be_emptied() {
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array() );

		try {
			$this->assertSame( array(), Miguel_Orders::get_deleted_order_statuses() );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	public function test_trash_deletes_even_when_it_is_not_configured() {
		// trash is a WordPress post status, not an order status, so it cannot appear in the
		// setting's choices — a trashed order is gone from the shop either way.
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array() );

		try {
			$this->assertTrue( Miguel_Orders::is_deleted_order_status( 'trash' ) );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	public function test_a_status_outside_the_setting_is_not_a_deletion() {
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array( 'refunded' ) );

		try {
			$this->assertTrue( Miguel_Orders::is_deleted_order_status( 'refunded' ) );
			$this->assertFalse( Miguel_Orders::is_deleted_order_status( 'cancelled' ),
				'cancelled was unticked, so it must no longer destroy the customer\'s access' );
			$this->assertFalse( Miguel_Orders::is_deleted_order_status( 'processing' ) );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	/**
	 * A partial refund reaches Miguel as the order without the refunded product; Miguel then
	 * deletes that item and expires its links.
	 */
	public function test_a_partial_refund_sends_the_order_without_the_refunded_product() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST' => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
			)
		);

		$kept     = Miguel_Helper_Product::create_miguel_product( 'kept-book' );
		$refunded = Miguel_Helper_Product::create_miguel_product( 'refunded-book' );

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $kept, 1 );
		$refunded_item_id = $order->add_product( $refunded, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $refunded_item_id, 1, 10.00 );
		$order = wc_get_order( $order->get_id() );

		$this->get_sut()->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[0]['method'] );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( array( 'kept-book' ), array_column( $body['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Refunding every Miguel product removes the order from Miguel even though WooCommerce keeps
	 * the order's status, because something else in it (here a non-Miguel line) was not refunded.
	 */
	public function test_refunding_every_miguel_product_deletes_the_order_in_miguel() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'DELETE' => array(
					'body'     => '',
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 0, 10.00 );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status(), 'only part of the order is refunded, so its status stays' );

		$sut = $this->get_sut();
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 1, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'DELETE', $requests[0]['method'] );
		$this->assertStringContains( '/v2/orders/' . $order->get_id(), $requests[0]['url'] );

		// The same state again sends nothing.
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );
		$this->assertCount( 1, Miguel_Helper_HTTP::get_requests() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * An order that never held a Miguel product is not deleted in Miguel because of a refund.
	 */
	public function test_a_refund_on_an_order_without_miguel_products_sends_nothing() {
		Miguel_Helper_HTTP::mock_api_responses( array() );

		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( Miguel_Helper_Product::create_virtual_product(), 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );
		$order = wc_get_order( $order->get_id() );

		$this->get_sut()->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$this->assertCount( 0, Miguel_Helper_HTTP::get_requests() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Deleting a refund gives the product back: the order is saved, which queues the usual sync,
	 * and that sync sends the product again.
	 */
	public function test_deleting_a_refund_resyncs_the_order_with_the_product_back() {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'POST'   => array(
					'body'     => '{}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
				),
				'DELETE' => array(
					'body'     => '',
					'response' => array( 'code' => 204, 'message' => 'No Content' ),
				),
			)
		);

		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		$refund = Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$sut   = $this->get_sut();
		$order = wc_get_order( $order->get_id() );
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );
		$this->assertEquals( 'DELETE', Miguel_Helper_HTTP::get_requests()[0]['method'] );

		$refund_id = $refund->get_id();
		$refund->delete( true );

		$saved    = array();
		$listener = function ( $order_id ) use ( &$saved ) {
			$saved[] = $order_id;
		};
		add_action( 'woocommerce_update_order', $listener );
		$sut->handle_refund_deleted( $refund_id, $order->get_id() );
		remove_action( 'woocommerce_update_order', $listener );

		$this->assertContains( $order->get_id(), $saved, 'deleting a refund must save the order, which is what queues its sync' );

		$order = wc_get_order( $order->get_id() );
		$sut->sync_order( $order->get_id(), '', $order->get_status(), $order );

		$requests = Miguel_Helper_HTTP::get_requests();
		$this->assertCount( 2, $requests, 'Different number of requests: ' . print_r( $requests, true ) );
		$this->assertEquals( 'POST', $requests[1]['method'] );
		$body = json_decode( $requests[1]['body'], true );
		$this->assertSame( array( 'dummy-name' ), array_column( $body['items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_handle_refund_deleted_ignores_a_missing_order() {
		$saved    = array();
		$listener = function ( $order_id ) use ( &$saved ) {
			$saved[] = $order_id;
		};
		add_action( 'woocommerce_update_order', $listener );
		$this->get_sut()->handle_refund_deleted( 0, 999999 );
		remove_action( 'woocommerce_update_order', $listener );

		$this->assertSame( array(), $saved );
	}

	public function test_registers_the_refund_deleted_hook() {
		$hook_manager = new Miguel_Hook_Manager();
		$sut          = $this->create_service_with_mocks(
			'Miguel_Orders',
			array(
				'hook_manager' => $hook_manager,
				'client'       => new Miguel_V2_Client( 'https://example.com', 'test-token' ),
			)
		);

		$sut->register_hooks();

		try {
			$this->assertTrue( $hook_manager->is_hook_registered( 'woocommerce_refund_deleted', array( $sut, 'handle_refund_deleted' ) ) );

			$registered = array_values(
				array_filter(
					$hook_manager->get_registered_hooks(),
					function ( $hook ) {
						return 'woocommerce_refund_deleted' === $hook['hook'];
					}
				)
			);
			$this->assertSame( 2, $registered[0]['accepted_args'] );
		} finally {
			$hook_manager->remove_all_hooks();
		}
	}
}
