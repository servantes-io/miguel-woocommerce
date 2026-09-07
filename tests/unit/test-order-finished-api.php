<?php
/**
 * Test the Miguel order-finished callback route.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Order_Finished_Api extends Miguel_Test_Case {

	public function tearDown(): void {
		delete_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION );
		delete_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION );
		parent::tearDown();
	}

	/**
	 * Build a request for the finished route.
	 *
	 * @param int   $order_id Order id.
	 * @param array $payload  JSON body.
	 * @param string|null $idempotency_key Key, or null to omit the header.
	 * @return WP_REST_Request
	 */
	private function make_request( $order_id, $payload, $idempotency_key = 'idem-finished-1' ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders/' . $order_id . '/finished' );
		// Mirror how WP_REST_Server::dispatch() populates a route match: into the URL
		// bucket via set_url_params(), not set_param() — the payload's own top-level "id"
		// (Miguel's order id) would otherwise outrank a generic set_param() in WP_REST_Request's
		// parameter precedence, and the handler resolves the order from the URL match alone.
		$request->set_url_params( array( 'id' => $order_id ) );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $idempotency_key ) {
			$request->set_header( 'Idempotency-Key', $idempotency_key );
		}
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	/**
	 * A finished-order payload with the given products.
	 *
	 * @param array  $products     Products array.
	 * @param string $miguel_state Miguel state.
	 * @return array
	 */
	private function payload( $products, $miguel_state = 'finished' ) {
		return array(
			'id' => 1,
			'code' => '1',
			'paid' => true,
			'miguel_state' => $miguel_state,
			'products' => $products,
		);
	}

	/**
	 * One product carrying at least one format — a Miguel product.
	 *
	 * @return array
	 */
	private function miguel_product() {
		return array(
			'code' => 'harry-potter',
			'formats' => array( array( 'task_id' => 1, 'format' => 'epub', 'downloads' => array() ) ),
		);
	}

	/**
	 * One product with no formats — not a Miguel product.
	 *
	 * @return array
	 */
	private function other_product() {
		return array( 'code' => 'mug', 'formats' => array() );
	}

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with( 'rest_api_init', $this->isType( 'array' ) );

		$api = new Miguel_Order_Finished_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_status_choices_start_with_do_not_change() {
		$choices = Miguel_Order_Finished_Api::get_status_choices();

		$this->assertArrayHasKey( '', $choices );
		$this->assertSame( '', array_key_first( $choices ) );
		$this->assertArrayHasKey( 'completed', $choices );
		$this->assertArrayNotHasKey( 'wc-completed', $choices );
		$this->assertArrayNotHasKey( 'paid', $choices );
	}

	public function test_does_not_change_status_when_state_is_not_finished() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ), 'failed' ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertFalse( $data['changed'] );
		$this->assertSame( 'waiting for status: finished', $data['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_does_not_change_status_when_products_are_empty() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array() ) )
		);

		$this->assertSame( 'no products', $response->get_data()['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_applies_miguel_only_target_when_every_product_has_formats() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request(
				$order->get_id(),
				$this->payload( array( $this->miguel_product(), $this->miguel_product() ) )
			)
		);

		$data = $response->get_data();
		$this->assertTrue( $data['changed'] );
		$this->assertSame( 'state changed', $data['reason'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_one_product_without_formats_selects_the_mixed_target() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request(
				$order->get_id(),
				$this->payload( array( $this->miguel_product(), $this->other_product() ) )
			)
		);

		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_does_not_change_status_when_target_is_unset() {
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertSame( 'auto change not set', $response->get_data()['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_does_not_change_status_when_configured_target_no_longer_exists() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'deleted-custom-status' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'configured status no longer exists', $response->get_data()['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_returns_404_for_unknown_order() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( 99999999, $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'order.not_found', $response->get_error_code() );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_returns_400_when_idempotency_key_missing() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$order = Miguel_Helper_Order::create_order();
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ), null )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'idempotency.key_required', $response->get_error_code() );
	}

	public function test_replays_the_stored_result_for_a_repeated_key() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'processing' );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );
		$payload = $this->payload( array( $this->miguel_product() ) );

		$first = $api->handle_order_finished( $this->make_request( $order->get_id(), $payload, 'idem-replay' ) );
		$this->assertFalse( $first->get_data()['idempotent_replay'] );

		wc_get_order( $order->get_id() )->update_status( 'processing' );

		$second = $api->handle_order_finished( $this->make_request( $order->get_id(), $payload, 'idem-replay' ) );

		$this->assertTrue( $second->get_data()['idempotent_replay'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}
}
