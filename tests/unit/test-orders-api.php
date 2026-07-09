<?php
/**
 * Test Miguel orders API.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Orders_Api extends Miguel_Test_Case {

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with(
				'rest_api_init',
				$this->isType( 'array' )
			);

		$api = new Miguel_Orders_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_get_orders_returns_400_when_updated_since_missing() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );

		$response = $api->get_orders( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'argument.missing', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_get_orders_returns_400_when_updated_since_invalid() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', 'not-a-date' );

		$response = $api->get_orders( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'argument.invalid', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_get_orders_returns_correct_structure() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', '2000-01-01 00:00:00' );

		$response = $api->get_orders( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'count', $data );
		$this->assertArrayHasKey( 'orders', $data );
		$this->assertIsArray( $data['orders'] );
		$this->assertSame( count( $data['orders'] ), $data['count'] );
	}

	public function test_get_orders_includes_orders_modified_since_date() {
		$order = wc_create_order( array( 'status' => 'processing' ) );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', '2000-01-01 00:00:00' );

		$response = $api->get_orders( $request );
		$data     = $response->get_data();

		$ids = array_column( $data['orders'], 'id' );
		$this->assertContains( strval( $order->get_id() ), $ids );
	}

	public function test_get_orders_excludes_orders_before_updated_since() {
		$order = wc_create_order( array( 'status' => 'processing' ) );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', gmdate( 'Y-m-d H:i:s', time() + 86400 ) );

		$response = $api->get_orders( $request );
		$data     = $response->get_data();

		$ids = array_column( $data['orders'], 'id' );
		$this->assertNotContains( strval( $order->get_id() ), $ids );
	}

	public function test_get_orders_order_has_required_fields() {
		$order = wc_create_order( array( 'status' => 'processing' ) );
		$order->set_billing_email( 'test@example.com' );
		$order->set_billing_first_name( 'Jan' );
		$order->set_billing_last_name( 'Novak' );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', '2000-01-01 00:00:00' );

		$response = $api->get_orders( $request );
		$data     = $response->get_data();

		$found = null;
		foreach ( $data['orders'] as $o ) {
			if ( $o['id'] === strval( $order->get_id() ) ) {
				$found = $o;
				break;
			}
		}

		$this->assertNotNull( $found, 'Order not found in response' );
		$this->assertArrayHasKey( 'id', $found );
		$this->assertArrayHasKey( 'status', $found );
		$this->assertArrayHasKey( 'currency_code', $found );
		$this->assertArrayHasKey( 'paid', $found );
		$this->assertArrayHasKey( 'purchase_date', $found );
		$this->assertArrayHasKey( 'update_date', $found );
		$this->assertArrayHasKey( 'user', $found );
		$this->assertArrayHasKey( 'products', $found );

		$this->assertArrayHasKey( 'id', $found['user'] );
		$this->assertArrayHasKey( 'email', $found['user'] );
		$this->assertArrayHasKey( 'full_name', $found['user'] );
		$this->assertArrayHasKey( 'address', $found['user'] );
		$this->assertArrayHasKey( 'lang', $found['user'] );
	}

	public function test_get_order_returns_404_when_not_found() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/999999' );
		$request->set_param( 'id', 999999 );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.not_found', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_get_order_returns_404_for_refund() {
		$order  = Miguel_Helper_Order::create_order();
		$refund = wc_create_refund( array( 'order_id' => $order->get_id() ) );

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $refund->get_id() );
		$request->set_param( 'id', $refund->get_id() );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.not_found', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_get_order_returns_base_fields() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$response = $api->get_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		foreach ( array( 'id', 'status', 'currency_code', 'paid', 'purchase_date', 'update_date', 'user', 'products' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( strval( $order->get_id() ), $data['id'] );
	}
}
