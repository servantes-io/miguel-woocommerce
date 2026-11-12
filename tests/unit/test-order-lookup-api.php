<?php
/**
 * Test the lookup of an order Miguel created, by the idempotency key it created it with.
 *
 * Miguel asks this when its create request got no answer: whether the shop has the order, without
 * creating it (a repeated create would).
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Order_Lookup_Api extends Miguel_Test_Case {

	/**
	 * Create an order through the create API.
	 *
	 * @param string $key Idempotency key.
	 * @return int Order ID.
	 */
	private function create_order_with_key( $key ) {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$payload = array(
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
		);

		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		$response = ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $request );
		$this->assertSame( 201, $response->get_status() );

		return (int) $response->get_data()['id'];
	}

	/**
	 * Look an order up by its key.
	 *
	 * @param string $key Idempotency key.
	 * @return WP_REST_Response|WP_Error
	 */
	private function lookup( $key ) {
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/lookup' );
		$request->set_param( 'idempotency_key', $key );

		return ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->lookup_order( $request );
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

	public function test_route_is_registered_behind_the_api_key() {
		global $wp_rest_server;
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );
		// A fresh server fires rest_api_init again: an earlier test may already have built one.
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/miguel/v1/orders/lookup', $routes );
		$route = $routes['/miguel/v1/orders/lookup'][0];
		$this->assertArrayHasKey( 'GET', $route['methods'] );
		$this->assertSame( array( $api, 'validate_api_access' ), $route['permission_callback'] );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/miguel/v1/orders/lookup' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_lookup_finds_the_order_created_with_the_key() {
		$key = 'MIGUEL-LOOKUP-' . wp_generate_uuid4();
		$order_id = $this->create_order_with_key( $key );

		$result = $this->lookup( $key );

		$this->assertSame( 200, $this->status_of( $result ) );
		$this->assertSame( $order_id, $result->get_data()['id'] );
		$this->assertSame( wc_get_order( $order_id )->get_status(), $result->get_data()['status'] );
		$this->assertNotEmpty( $result->get_data()['date_created_gmt'] );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	public function test_lookup_creates_nothing() {
		$key = 'MIGUEL-LOOKUP-' . wp_generate_uuid4();
		$before = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );

		$result = $this->lookup( $key );

		$this->assertSame( 404, $this->status_of( $result ) );
		$this->assertSame( 'order.not_found', $result->get_error_code() );
		$this->assertSame( $before, count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) ) );
	}

	public function test_lookup_finds_the_order_by_its_meta_when_the_stored_result_is_gone() {
		$key = 'MIGUEL-LOOKUP-' . wp_generate_uuid4();
		$order_id = $this->create_order_with_key( $key );
		delete_option( 'miguel_order_idem_result_' . hash( 'sha256', $key ) );

		$result = $this->lookup( $key );

		$this->assertSame( 200, $this->status_of( $result ) );
		$this->assertSame( $order_id, $result->get_data()['id'] );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	public function test_lookup_reports_a_create_in_progress() {
		$key = 'MIGUEL-LOOKUP-' . wp_generate_uuid4();
		add_option( 'miguel_order_idem_lock_' . hash( 'sha256', $key ), (string) time(), '', 'no' );

		$result = $this->lookup( $key );

		$this->assertSame( 409, $this->status_of( $result ) );
		$this->assertSame( 'idempotency.in_progress', $result->get_error_code() );
	}

	public function test_lookup_ignores_a_lock_left_by_a_create_that_died() {
		$key = 'MIGUEL-LOOKUP-' . wp_generate_uuid4();
		add_option( 'miguel_order_idem_lock_' . hash( 'sha256', $key ), (string) ( time() - HOUR_IN_SECONDS ), '', 'no' );

		$result = $this->lookup( $key );

		$this->assertSame( 404, $this->status_of( $result ) );
	}

	public function test_lookup_without_a_key_is_400() {
		$result = $this->lookup( '' );

		$this->assertSame( 400, $this->status_of( $result ) );
		$this->assertSame( 'idempotency.key_required', $result->get_error_code() );
	}
}
