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

	/**
	 * How many callbacks are attached to the payment-complete status filter.
	 *
	 * @return int
	 */
	private function count_payment_complete_filters() {
		$hook = 'woocommerce_valid_order_statuses_for_payment_complete';
		if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
			return 0;
		}

		return count( $GLOBALS['wp_filter'][ $hook ]->callbacks, COUNT_RECURSIVE );
	}

	/**
	 * Reproduces a Test-environment failure: a shop whose payment gateway parks orders in its own
	 * custom status. WooCommerce's payment_complete() only acts on on-hold/pending/failed/cancelled,
	 * so from any other status it silently does nothing — and still returns true. The order stayed
	 * "awaiting", is_paid() was false, and the route reported
	 * order.status_update_failed / 500.
	 */
	public function test_marks_paid_from_a_gateway_custom_status() {
		$add_status = function ( $statuses ) {
			return array_merge( $statuses, array( 'wc-awaiting' => 'Awaiting' ) );
		};
		register_post_status( 'wc-awaiting', array(
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		) );
		add_filter( 'wc_order_statuses', $add_status );

		try {
			$order = Miguel_Helper_Order::create_order();
			$order->update_status( 'awaiting' );
			$this->assertSame( 'awaiting', wc_get_order( $order->get_id() )->get_status(),
				'precondition: the order sits in the gateway custom status' );

			$hooks_before = $this->count_payment_complete_filters();

			$result = ( new Miguel_Order_Status_Writer() )->apply(
				$order->get_id(), 'paid', 'idem-custom-' . wp_generate_uuid4() );

			$this->assertIsArray( $result,
				is_wp_error( $result ) ? 'writer failed: ' . $result->get_error_message() : '' );

			$reloaded = wc_get_order( $order->get_id() );
			$this->assertTrue( $reloaded->is_paid(),
				'the order must end up paid, not stranded in the custom status' );
			$this->assertNotEmpty( $reloaded->get_date_paid(),
				'payment_complete() must have run, so date_paid is set' );

			// Not has_filter(): WooCommerce core keeps its own DraftOrders callback on this hook,
			// so the hook is never empty. Count the callbacks instead.
			$this->assertSame( $hooks_before, $this->count_payment_complete_filters(),
				'the widening filter must not outlive the call — it would change payment completion '
				. 'for every other gateway in the shop' );
		} finally {
			remove_filter( 'wc_order_statuses', $add_status );
		}
	}

	/**
	 * Register a gateway-style custom order status for the duration of a test.
	 *
	 * @return callable The wc_order_statuses filter, for the caller to remove.
	 */
	private function register_gateway_status( $slug = 'awaiting', $label = 'Awaiting' ) {
		register_post_status( 'wc-' . $slug, array( 'public' => true ) );
		$add = function ( $statuses ) use ( $slug, $label ) {
			return array_merge( $statuses, array( 'wc-' . $slug => $label ) );
		};
		add_filter( 'wc_order_statuses', $add );

		return $add;
	}

	/**
	 * A shop whose gateway sends payment_complete() straight back to its own status.
	 *
	 * Payment completion really runs — woocommerce_payment_complete fires, date_paid is set, the
	 * gateway is notified — but the order never reaches processing/completed, so is_paid() stays
	 * false. The route used to call that a 500, which is a server fault the shop cannot fix and
	 * Miguel retried once a minute forever, re-firing woocommerce_payment_complete every time.
	 * Payment ran, so this is success.
	 */
	public function test_paid_succeeds_when_the_shop_keeps_its_own_status() {
		$add = $this->register_gateway_status();
		$redirect = function () { return 'awaiting'; };
		add_filter( 'woocommerce_payment_complete_order_status', $redirect );

		try {
			$order = Miguel_Helper_Order::create_order();
			$order->update_status( 'awaiting' );

			$result = ( new Miguel_Order_Status_Writer() )->apply(
				$order->get_id(), 'paid', 'kept-' . wp_generate_uuid4() );

			$this->assertIsArray( $result,
				is_wp_error( $result ) ? 'writer failed: ' . $result->get_error_code() : '' );
			$this->assertTrue( $result['paid'], 'payment completion ran, so the order counts as paid' );
			$this->assertSame( 'awaiting', $result['status'],
				'where the shop parks the order is the shop\'s business, and is reported as-is' );
		} finally {
			remove_filter( 'woocommerce_payment_complete_order_status', $redirect );
			remove_filter( 'wc_order_statuses', $add );
		}
	}

	/**
	 * The same shop, on Miguel's next sweep: the stored idempotency result replays and the order
	 * is never touched again. This is what stops woocommerce_payment_complete re-firing per retry.
	 */
	public function test_paid_replays_instead_of_completing_payment_twice() {
		$add = $this->register_gateway_status();
		$redirect = function () { return 'awaiting'; };
		add_filter( 'woocommerce_payment_complete_order_status', $redirect );

		$fired = 0;
		$count = function () use ( &$fired ) { $fired++; };
		add_action( 'woocommerce_payment_complete', $count );

		try {
			$order = Miguel_Helper_Order::create_order();
			$order->update_status( 'awaiting' );
			$key = 'miguel-order-status-paid-' . $order->get_id();
			$writer = new Miguel_Order_Status_Writer();

			$first = $writer->apply( $order->get_id(), 'paid', $key );
			$second = $writer->apply( $order->get_id(), 'paid', $key );
			$third = $writer->apply( $order->get_id(), 'paid', $key );

			$this->assertIsArray( $first );
			$this->assertIsArray( $second );
			$this->assertIsArray( $third );
			$this->assertFalse( $first['idempotent_replay'] );
			$this->assertTrue( $second['idempotent_replay'] );
			$this->assertTrue( $third['idempotent_replay'] );
			$this->assertTrue( $second['paid'], 'a replay reports the outcome it replays' );
			$this->assertSame( 1, $fired,
				'payment completion must run once, however many times Miguel sweeps' );
		} finally {
			remove_action( 'woocommerce_payment_complete', $count );
			remove_filter( 'woocommerce_payment_complete_order_status', $redirect );
			remove_filter( 'wc_order_statuses', $add );
		}
	}

	/**
	 * A shop that refuses outright: something resets the valid-status list at a later priority, so
	 * payment_complete() takes its else branch and completes nothing. The order genuinely is not
	 * paid — but an operator's gateway configuration is not a server fault, so this is 200 with
	 * paid:false and a reason, the same shape the finished route uses for changed:false. Miguel
	 * records it and stops instead of retrying a shop that will keep saying no.
	 */
	public function test_paid_reports_a_refusal_without_failing() {
		$add = $this->register_gateway_status();
		$clobber = function () {
			return array( 'on-hold', 'pending', 'failed', 'cancelled' );
		};
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $clobber, 99 );

		try {
			$order = Miguel_Helper_Order::create_order();
			$order->update_status( 'awaiting' );

			$result = ( new Miguel_Order_Status_Writer() )->apply(
				$order->get_id(), 'paid', 'refused-' . wp_generate_uuid4() );

			$this->assertIsArray( $result, 'a refusal is not an error' );
			$this->assertFalse( $result['paid'] );
			$this->assertNotEmpty( $result['reason'] );
			$this->assertSame( 'awaiting', $result['status'] );
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $clobber, 99 );
			remove_filter( 'wc_order_statuses', $add );
		}
	}

	/**
	 * A refusal must not be remembered. The operator fixes their gateway, Miguel or an admin calls
	 * again with the same key, and it has to be able to succeed — a stored refusal would replay
	 * "no" forever.
	 */
	public function test_a_refusal_is_not_stored_for_replay() {
		$add = $this->register_gateway_status();
		$clobber = function () {
			return array( 'on-hold', 'pending', 'failed', 'cancelled' );
		};
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $clobber, 99 );

		$order = Miguel_Helper_Order::create_order();
		$order->update_status( 'awaiting' );
		$key = 'miguel-order-status-paid-' . $order->get_id();
		$writer = new Miguel_Order_Status_Writer();

		try {
			$refused = $writer->apply( $order->get_id(), 'paid', $key );
			$this->assertFalse( $refused['paid'] );
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $clobber, 99 );
		}

		try {
			$retried = $writer->apply( $order->get_id(), 'paid', $key );

			$this->assertTrue( $retried['paid'], 'the same key must still be able to succeed later' );
			$this->assertFalse( $retried['idempotent_replay'] );
		} finally {
			remove_filter( 'wc_order_statuses', $add );
		}
	}
}
