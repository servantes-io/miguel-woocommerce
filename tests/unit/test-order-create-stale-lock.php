<?php
/**
 * A create that died (a PHP fatal, a killed worker) never deletes its idempotency lock. The key must not stay
 * "in progress" forever: an old lock is taken over, without creating a second order when the dead create made one.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Order_Create_Stale_Lock extends Miguel_Test_Case {

	/**
	 * A create request for a downloadable product.
	 *
	 * @param string $key Idempotency key.
	 * @return WP_REST_Request
	 */
	private function create_request( $key ) {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'idempotency_key' => $key,
					'payment_method'  => 'cod',
					'billing'         => array(
						'first_name' => 'Test',
						'email'      => 'buyer@example.com',
					),
					'shipping'        => array(
						'first_name' => 'Test',
					),
					'shipping_lines'  => array(
						array(
							'method_id' => 'flat_rate',
							'total'     => '0.00',
						),
					),
					'line_items'      => array(
						array(
							'product_id' => $product->get_id(),
							'quantity'   => 1,
						),
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Hold the key's lock, as a create that started at the given time would.
	 *
	 * @param string $key       Idempotency key.
	 * @param int    $locked_at Unix time the lock was taken.
	 */
	private function lock( $key, $locked_at ) {
		add_option( 'miguel_order_idem_lock_' . hash( 'sha256', $key ), (string) $locked_at, '', 'no' );
	}

	/**
	 * The HTTP status of a response or an error.
	 *
	 * @param WP_REST_Response|WP_Error $result Result.
	 * @return int
	 */
	private function status_of( $result ) {
		return is_wp_error( $result ) ? (int) $result->get_error_data()['status'] : $result->get_status();
	}

	public function test_a_fresh_lock_is_still_a_create_in_progress() {
		$key = 'MIGUEL-LOCK-' . wp_generate_uuid4();
		$this->lock( $key, time() );

		$result = ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $this->create_request( $key ) );

		$this->assertSame( 409, $this->status_of( $result ) );
		$this->assertSame( 'idempotency.in_progress', $result->get_error_code() );
	}

	public function test_a_lock_left_by_a_create_that_died_is_taken_over() {
		$key = 'MIGUEL-LOCK-' . wp_generate_uuid4();
		$this->lock( $key, time() - HOUR_IN_SECONDS );

		$result = ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $this->create_request( $key ) );

		$this->assertSame( 201, $this->status_of( $result ) );
		$this->assertFalse( get_option( 'miguel_order_idem_lock_' . hash( 'sha256', $key ), false ), 'the lock is released' );

		Miguel_Helper_Order::delete_order( $result->get_data()['id'] );
	}

	public function test_a_dead_create_that_made_its_order_is_replayed_not_repeated() {
		$key = 'MIGUEL-LOCK-' . wp_generate_uuid4();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$first = $api->create_order( $this->create_request( $key ) );
		$this->assertSame( 201, $first->get_status() );
		// The order and its key are saved, but the create died before it stored its result and released its lock.
		delete_option( 'miguel_order_idem_result_' . hash( 'sha256', $key ) );
		$this->lock( $key, time() - HOUR_IN_SECONDS );

		$retry = $api->create_order( $this->create_request( $key ) );

		$this->assertSame( 200, $this->status_of( $retry ) );
		$this->assertTrue( $retry->get_data()['idempotent_replay'] );
		$this->assertSame( $first->get_data()['id'], $retry->get_data()['id'] );

		Miguel_Helper_Order::delete_order( $first->get_data()['id'] );
	}
}
