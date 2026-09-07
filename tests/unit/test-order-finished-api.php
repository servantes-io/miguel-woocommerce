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
	 * One payload product carrying at least one format.
	 *
	 * The payload only has to get past the "no products" gate — cart composition is read
	 * from the WooCommerce order's own line items, never from here.
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
	 * A processing order holding the given products, one of each.
	 *
	 * @param WC_Product[] $products Products to add as line items.
	 * @return WC_Order
	 */
	private function create_order_with( $products ) {
		$order = wc_create_order( array( 'status' => 'processing', 'customer_id' => 0 ) );
		foreach ( $products as $product ) {
			$order->add_product( $product, 1 );
		}
		$order->set_billing_email( 'test@melvil.cz' );
		$order->save();
		$order->update_status( 'processing' );

		return $order;
	}

	/**
	 * An order whose every line item is a Miguel book (downloadable + Miguel shortcode).
	 *
	 * @param int $count How many books.
	 * @return WC_Order
	 */
	private function create_miguel_only_order( $count = 1 ) {
		$products = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$products[] = Miguel_Helper_Product::create_downloadable_product();
		}

		return $this->create_order_with( $products );
	}

	/**
	 * An order holding one Miguel book and one ordinary product.
	 *
	 * The ordinary product is a plain virtual product: not downloadable and carrying no
	 * Miguel shortcode, so Miguel_Order_Mapper exports no code for it — which is exactly
	 * why it never appears in the callback's products[].
	 *
	 * @return WC_Order
	 */
	private function create_mixed_order() {
		return $this->create_order_with(
			array(
				Miguel_Helper_Product::create_downloadable_product(),
				Miguel_Helper_Product::create_virtual_product(),
			)
		);
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
		$order = $this->create_miguel_only_order();
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
		$order = $this->create_miguel_only_order();
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array() ) )
		);

		$this->assertSame( 'no products', $response->get_data()['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_applies_miguel_only_target_when_every_line_item_is_a_miguel_product() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = $this->create_miguel_only_order( 2 );
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

	public function test_a_non_miguel_line_item_selects_the_mixed_target() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = $this->create_mixed_order();
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		// The non-Miguel line item is absent from products[] — the mapper never sent it.
		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_a_line_item_whose_product_was_deleted_selects_the_mixed_target() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$book = Miguel_Helper_Product::create_downloadable_product();
		$gone = Miguel_Helper_Product::create_virtual_product();
		$gone->save();
		$order = $this->create_order_with( array( $book, $gone ) );
		Miguel_Helper_Product::delete_product( $gone->get_id() );

		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );
		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_a_shipping_line_does_not_flip_an_all_book_order_to_mixed() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = $this->create_miguel_only_order();
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_total( 5 );
		$order->add_item( $shipping );
		$order->save();

		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );
		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * The payload no longer decides composition: a Miguel book whose code did not resolve
	 * in the workspace comes back without formats, and that must not be read as "the
	 * customer also bought something else".
	 */
	public function test_a_formats_less_payload_product_does_not_flip_an_all_book_order_to_mixed() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		update_option( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, 'on-hold' );
		$order = $this->create_miguel_only_order( 2 );
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request(
				$order->get_id(),
				$this->payload(
					array(
						$this->miguel_product(),
						array( 'code' => 'unresolved-book', 'formats' => array() ),
					)
				)
			)
		);

		$this->assertTrue( $response->get_data()['changed'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_does_not_change_status_when_target_is_unset() {
		$order = $this->create_miguel_only_order();
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ) )
		);

		$this->assertSame( 'auto change not set', $response->get_data()['reason'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_does_not_change_status_when_configured_target_no_longer_exists() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'deleted-custom-status' );
		$order = $this->create_miguel_only_order();
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
		$order = $this->create_miguel_only_order();
		$api = new Miguel_Order_Finished_Api( new Miguel_Hook_Manager() );

		$response = $api->handle_order_finished(
			$this->make_request( $order->get_id(), $this->payload( array( $this->miguel_product() ) ), null )
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'idempotency.key_required', $response->get_error_code() );
	}

	public function test_replays_the_stored_result_for_a_repeated_key() {
		update_option( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, 'completed' );
		$order = $this->create_miguel_only_order();
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
