<?php
/**
 * Test Miguel order status update API.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Order_Status_Update_Api extends Miguel_Test_Case {

	/**
	 * Set a JSON request body so WP_REST_Request::get_json_params() can parse it.
	 *
	 * @param WP_REST_Request $request Request to populate.
	 * @param array           $params  Parameters to encode as the JSON body.
	 */
	private function set_json_body( $request, $params ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with(
				'rest_api_init',
				$this->isType( 'array' )
			);

		$api = new Miguel_Order_Status_Update_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_update_order_status_returns_400_when_status_missing() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array()
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.status_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_returns_409_when_status_invalid() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array(
				'status' => 'definitely-not-valid',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.status_invalid', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_returns_400_when_idempotency_key_missing() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$this->set_json_body( $request,
			array(
				'status' => 'completed',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'idempotency.key_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_returns_404_when_order_not_found() {
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/999999/status' );
		$request->set_param( 'id', 999999 );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array(
				'status' => 'completed',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'order.not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_updates_order_and_returns_success_response() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array(
				'status' => 'completed',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order->get_id(), $response->get_data()['id'] );
		$this->assertSame( 'completed', $response->get_data()['status'] );
		$this->assertFalse( $response->get_data()['idempotent_replay'] );
	}

	public function test_update_order_status_replays_response_for_same_idempotency_key() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$idempotency_key = 'idem-status-' . wp_generate_uuid4();

		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', $idempotency_key );
		$this->set_json_body( $request,
			array(
				'status' => 'completed',
			)
		);

		$first_response = $api->update_order_status( $request );
		$second_response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $first_response );
		$this->assertInstanceOf( WP_REST_Response::class, $second_response );
		$this->assertFalse( $first_response->get_data()['idempotent_replay'] );
		$this->assertTrue( $second_response->get_data()['idempotent_replay'] );
		$this->assertSame( 'completed', $second_response->get_data()['status'] );
	}

	public function test_update_order_status_returns_payload_mismatch_for_same_key_with_different_status() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$idempotency_key = 'idem-status-' . wp_generate_uuid4();

		$first_request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$first_request->set_param( 'id', $order->get_id() );
		$first_request->set_header( 'Idempotency-Key', $idempotency_key );
		$this->set_json_body( $first_request,
			array(
				'status' => 'completed',
			)
		);
		$api->update_order_status( $first_request );

		$second_request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$second_request->set_param( 'id', $order->get_id() );
		$second_request->set_header( 'Idempotency-Key', $idempotency_key );
		$this->set_json_body( $second_request,
			array(
				'status' => 'processing',
			)
		);

		$response = $api->update_order_status( $second_request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'idempotency.payload_mismatch', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_accepts_idempotency_key_from_header() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array(
				'status' => 'completed',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_update_order_status_ignores_body_idempotency_key_without_header() {
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$this->set_json_body( $request,
			array(
				'idempotency_key' => 'idem-status-body-only',
				'status' => 'completed',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'idempotency.key_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_update_order_status_supports_paid_pseudo_status() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_status( 'pending' );
		$order->set_date_paid( null );
		$order->save();

		$api = new Miguel_Order_Status_Update_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'PATCH', '/miguel/v1/orders/' . $order->get_id() . '/status' );
		$request->set_param( 'id', $order->get_id() );
		$request->set_header( 'Idempotency-Key', 'idem-status-' . wp_generate_uuid4() );
		$this->set_json_body( $request,
			array(
				'status' => 'paid',
			)
		);

		$response = $api->update_order_status( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['idempotent_replay'] );

		$updated_order = wc_get_order( $order->get_id() );
		$this->assertTrue( $updated_order->is_paid() );
		$this->assertContains( $response->get_data()['status'], array( 'processing', 'completed' ) );
	}
}
