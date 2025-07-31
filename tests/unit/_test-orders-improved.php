<?php
/**
 * Improved tests for Miguel Orders functionality with dependency injection
 *
 * @package Miguel\Tests
 */

use Servantes\Miguel\Interfaces\HookManagerInterface;

class Test_Miguel_Orders_Improved extends Miguel_Test_Case {

	/**
	 * Test that hooks are registered correctly
	 */
	public function test_orders_registers_correct_hooks() {
		$hook_manager_mock = $this->createMock( HookManagerInterface::class );

		// Expect the correct hook registrations
		$hook_manager_mock->expects( $this->exactly( 2 ) )
			->method( 'add_action' )
			->withConsecutive(
				[ 'woocommerce_order_status_changed', $this->anything(), 10, 4 ],
				[ 'woocommerce_update_order', $this->anything(), 10, 1 ]
			);

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'hook_manager' => $hook_manager_mock
		] );

		$orders->register_hooks();
	}

	/**
	 * Test order deletion sync
	 */
	public function test_sync_order_deletion() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->once() )
				 ->method( 'delete_order' )
				 ->with( '123' )
				 ->willReturn( [ 'success' => true ] );

		$logger_mock = $this->createMock( WC_Logger::class );
		$logger_mock->expects( $this->once() )
					->method( 'add' )
					->with( 'miguel', $this->stringContains( 'Successfully deleted order 123' ) );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create test order
		$order = Miguel_Helper_Order::create_order();
		$order->set_id( 123 );
		$order->set_status( 'cancelled' );

		// Trigger order sync
		$orders->sync_order( 123, 'processing', 'cancelled', $order );
	}

	/**
	 * Test order sync with Miguel products
	 */
	public function test_sync_order_with_miguel_products() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->once() )
				 ->method( 'submit_order' )
				 ->with(
					 $this->callback( function( $order_data ) {
						 return isset( $order_data['code'] )
							&& isset( $order_data['products'] )
							&& is_array( $order_data['products'] );
					 } )
				 )
				 ->willReturn( [ 'success' => true ] );

		$logger_mock = $this->createMock( WC_Logger::class );
		$logger_mock->expects( $this->once() )
					->method( 'add' )
					->with( 'miguel', $this->stringContains( 'Successfully synced order' ) );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create order with Miguel product
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// Trigger order sync
		$orders->sync_order( $order->get_id(), 'pending', 'processing', $order );
	}

	/**
	 * Test sync_order with no changes (should skip sync)
	 */
	public function test_sync_order_skips_when_no_changes() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->never() )
				 ->method( 'submit_order' );

		$logger_mock = $this->createMock( WC_Logger::class );
		$logger_mock->expects( $this->never() )
					->method( 'add' );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create order and set initial hash
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// First sync to establish hash
		$orders->sync_order( $order->get_id(), 'pending', 'processing', $order );

		// Reset mocks for second sync
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->never() )
				 ->method( 'submit_order' );

		$orders_second = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Second sync with same data should be skipped
		$orders_second->sync_order( $order->get_id(), 'pending', 'processing', $order );
	}

	/**
	 * Test API error handling during sync
	 */
	public function test_api_error_handling_during_sync() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->once() )
				 ->method( 'submit_order' )
				 ->willReturn( new WP_Error( 'api_error', 'Connection failed' ) );

		$logger_mock = $this->createMock( WC_Logger::class );
		$logger_mock->expects( $this->once() )
					->method( 'add' )
					->with( 'miguel', $this->stringContains( 'Failed to sync order' ) );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create order with Miguel product
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// Trigger order sync (should handle error gracefully)
		$orders->sync_order( $order->get_id(), 'pending', 'processing', $order );
	}

	/**
	 * Test handle_order_update method
	 */
	public function test_handle_order_update() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->once() )
				 ->method( 'submit_order' )
				 ->willReturn( [ 'success' => true ] );

		$logger_mock = $this->createMock( WC_Logger::class );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create order with Miguel product
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// Test handle_order_update method
		$orders->handle_order_update( $order->get_id() );
	}

	/**
	 * Test sync with order containing no Miguel products (should skip)
	 */
	public function test_sync_order_skips_non_miguel_products() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->never() )
				 ->method( 'submit_order' );

		$logger_mock = $this->createMock( WC_Logger::class );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create order with regular (non-downloadable) product
		$order = Miguel_Helper_Order::create_order();
		$product = wc_get_product_factory()->get_product( 0, 'simple' );
		$product->set_name( 'Regular Product' );
		$product->set_price( 10 );
		$product->save();

		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// Trigger order sync (should skip due to no Miguel products)
		$orders->sync_order( $order->get_id(), 'pending', 'processing', $order );
	}

	/**
	 * Test sync_order with invalid order ID
	 */
	public function test_handle_order_update_with_invalid_order() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->never() )
				 ->method( 'submit_order' );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api' => $api_mock,
		] );

		// Test with non-existent order ID
		$orders->handle_order_update( 99999 );

		// Should handle gracefully and not call API
		$this->assertTrue( true ); // If we get here, the method handled the invalid ID gracefully
	}

	/**
	 * Test logger fallback when no logger is injected
	 */
	public function test_logger_fallback() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->method( 'submit_order' )
				 ->willReturn( new WP_Error( 'test_error', 'Test error message' ) );

		// Create orders instance without logger (should fall back to Miguel::log)
		$orders = new \Servantes\Miguel\Services\Orders(
			new \Servantes\Miguel\Utils\HookManager(),
			$api_mock
		);

		// Create order with Miguel product
		$order = Miguel_Helper_Order::create_order();
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->save();

		// This should use the fallback logging and not throw an error
		$orders->sync_order( $order->get_id(), 'pending', 'processing', $order );

		// If we get here without errors, the fallback worked
		$this->assertTrue( true );
	}

	/**
	 * Test order deletion with API error
	 */
	public function test_order_deletion_with_api_error() {
		$api_mock = $this->createMock( Miguel_API::class );
		$api_mock->expects( $this->once() )
				 ->method( 'delete_order' )
				 ->willReturn( new WP_Error( 'delete_error', 'Failed to delete' ) );

		$logger_mock = $this->createMock( WC_Logger::class );
		$logger_mock->expects( $this->once() )
					->method( 'add' )
					->with( 'miguel', $this->stringContains( 'Failed to delete order' ) );

		$orders = $this->create_service_with_mocks( 'Miguel_Orders', [
			'api'    => $api_mock,
			'logger' => $logger_mock,
		] );

		// Create test order
		$order = Miguel_Helper_Order::create_order();
		$order->set_id( 123 );
		$order->set_status( 'cancelled' );

		// Trigger order deletion sync with error
		$orders->sync_order( 123, 'processing', 'cancelled', $order );
	}
}
