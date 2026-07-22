<?php
/**
 * Test Miguel order create API product code support.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Order_Create_Api extends Miguel_Test_Case {

	/**
	 * Build a minimal valid order payload for validation tests.
	 *
	 * @return array
	 */
	private function get_minimal_valid_payload() {
		return array(
			'payment_method' => 'cod',
			'billing' => array(
				'first_name' => 'Test',
			),
			'shipping' => array(
				'first_name' => 'Test',
			),
			'shipping_lines' => array(
				array(
					'method_id' => 'flat_rate',
					'total' => '0.00',
				),
			),
			'line_items' => array(
				array(
					'product_code' => 'dummy-name',
					'quantity' => 1,
				),
			),
		);
	}

	/**
	 * Test that productCode is translated to product_id in line items.
	 */
	public function test_prepare_payload_for_wc_order_maps_product_code_to_product_id() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'productCode' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $product->get_id(), $result['line_items'][0]['product_id'] );
		$this->assertArrayNotHasKey( 'productCode', $result['line_items'][0] );
	}

	/**
	 * Test that a printed-book code (SKU + configured suffix) resolves to the
	 * printed product when Miguel creates an order.
	 */
	public function test_prepare_payload_for_wc_order_maps_print_code_to_product_id() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$print = WC_Helper_Product::create_simple_product();
		$print->set_downloadable( false );
		$print->set_virtual( false );
		$print->set_sku( 'printed-book-42' );
		$print->save();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'printed-book-42:print',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $print->get_id(), $result['line_items'][0]['product_id'] );
		$this->assertArrayNotHasKey( 'product_code', $result['line_items'][0] );
	}

	/**
	 * Test that helper email flags are not forwarded to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_strips_send_email_flags() {
		Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'send_emails' => true,
				'send_email' => true,
				'line_items' => array(
					array(
						'product_code' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'send_emails', $result );
		$this->assertArrayNotHasKey( 'send_email', $result );
	}

	/**
	 * Test that email_template helper param is not forwarded to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_strips_email_template_flag() {
		Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'email_template' => 'customer_processing_order',
				'line_items' => array(
					array(
						'product_code' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'email_template', $result );
	}

	/**
	 * Test that ambiguous productCode is rejected.
	 */
	public function test_prepare_payload_for_wc_order_rejects_ambiguous_product_code() {
		$product_one = Miguel_Helper_Product::create_downloadable_product();
		$product_two = Miguel_Helper_Product::create_downloadable_product();

		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$product_one,
			array(
				'duplicate_epub_' . wp_generate_uuid4() => array(
					'name' => 'Duplicate Book 1',
					'file' => '[miguel id="duplicate-book" format="epub"]',
				),
			)
		);

		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$product_two,
			array(
				'duplicate_pdf_' . wp_generate_uuid4() => array(
					'name' => 'Duplicate Book 2',
					'file' => '[miguel id="duplicate-book" format="pdf"]',
				),
			)
		);

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'duplicate-book',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'product_code.ambiguous', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals(
			array( $product_one->get_id(), $product_two->get_id() ),
			$result->get_error_data()['product_ids']
		);
	}

	/**
	 * Test that quantity zero is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_zero_quantity() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'e-kniha-01',
						'quantity' => 0,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.invalid_quantity', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 0, $result->get_error_data()['quantity'] );
		$this->assertEquals( 0, $result->get_error_data()['line_item_index'] );
	}

	/**
	 * Test that negative quantity is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_negative_quantity() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'e-kniha-01',
						'quantity' => -2,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.invalid_quantity', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( -2, $result->get_error_data()['quantity'] );
		$this->assertEquals( 0, $result->get_error_data()['line_item_index'] );
	}

	/**
	 * Test that missing quantity is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_missing_quantity() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'e-kniha-01',
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.quantity_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 0, $result->get_error_data()['line_item_index'] );
	}

	/**
	 * Test that quantity must be a positive integer.
	 */
	public function test_prepare_payload_for_wc_order_rejects_non_integer_quantity() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_code' => 'e-kniha-01',
						'quantity' => '1.5',
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.invalid_quantity', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( '1.5', $result->get_error_data()['quantity'] );
	}

	/**
	 * Test that missing line_items is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_missing_line_items() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke( $api, array() );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_items.required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that empty line_items is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_empty_line_items() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_items.empty', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that non-array line item is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_invalid_line_item_structure() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					'invalid-line-item',
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.invalid_structure', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 0, $result->get_error_data()['line_item_index'] );
	}

	/**
	 * Test that missing product reference is rejected before passing payload to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_rejects_missing_product_reference() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.product_reference_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 0, $result->get_error_data()['line_item_index'] );
	}

	/**
	 * Test that conflicting product references are rejected.
	 */
	public function test_prepare_payload_for_wc_order_rejects_conflicting_product_references() {
		$product_one = Miguel_Helper_Product::create_downloadable_product();
		$product_two = Miguel_Helper_Product::create_virtual_product();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'line_items' => array(
					array(
						'product_id' => $product_two->get_id(),
						'product_code' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'line_item.product_reference_conflict', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( $product_two->get_id(), $result->get_error_data()['product_id'] );
		$this->assertEquals( $product_one->get_id(), $result->get_error_data()['resolved_product_id'] );
	}

	/**
	 * Test that missing status is allowed.
	 */
	public function test_validate_required_order_fields_accepts_missing_status() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['status'] );

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( true === $result );
	}

	/**
	 * Test send_emails flag normalization.
	 */
	public function test_should_send_order_emails_accepts_truthy_values() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'should_send_order_emails' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $api, array( 'send_emails' => true ) ) );
		$this->assertTrue( $method->invoke( $api, array( 'send_emails' => 'true' ) ) );
		$this->assertTrue( $method->invoke( $api, array( 'send_email' => 1 ) ) );
		$this->assertFalse( $method->invoke( $api, array( 'send_emails' => false ) ) );
		$this->assertFalse( $method->invoke( $api, array( 'send_emails' => 'false' ) ) );
		$this->assertTrue( $method->invoke( $api, array( 'email_template' => 'customer_processing_order' ) ) );
	}

	/**
	 * Test that invalid email_template is rejected.
	 */
	public function test_validate_required_order_fields_rejects_invalid_email_template() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		$payload['email_template'] = 'not_a_real_template';

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.email_template_invalid', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that invalid customer_id falls back to user_email when it matches an existing user.
	 */
	public function test_prepare_payload_for_wc_order_falls_back_from_invalid_customer_id_to_user_email() {
		Miguel_Helper_Product::create_downloadable_product();
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'fallback-user',
				'user_email' => 'fallback@example.com',
			)
		);

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		$payload['customer_id'] = 999999;
		$payload['user_email'] = 'fallback@example.com';

		try {
			$result = $method->invoke( $api, $payload );

			$this->assertIsArray( $result );
			$this->assertEquals( $user_id, $result['customer_id'] );
			$this->assertArrayHasKey( 'line_items', $result );
		} finally {
			wp_delete_user( $user_id );
		}
	}

	/**
	 * Test that a valid customer_id is preserved.
	 */
	public function test_prepare_payload_for_wc_order_keeps_valid_customer_id() {
		Miguel_Helper_Product::create_downloadable_product();
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'kept-user',
				'user_email' => 'kept@example.com',
			)
		);

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		try {
			$payload = $this->get_minimal_valid_payload();
			$payload['customer_id'] = $user_id;

			$result = $method->invoke( $api, $payload );

			$this->assertIsArray( $result );
			$this->assertEquals( $user_id, $result['customer_id'] );
		} finally {
			wp_delete_user( $user_id );
		}
	}

	/**
	 * Test that invalid customer_id and missing user_email produce a guest order payload.
	 */
	public function test_prepare_payload_for_wc_order_removes_customer_id_when_no_email_match_exists() {
		Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		$payload['customer_id'] = 999999;

		$result = $method->invoke( $api, $payload );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'customer_id', $result );
	}

	/**
	 * Test that payment_method is required.
	 */
	public function test_validate_required_order_fields_rejects_missing_payment_method() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['payment_method'] );

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.payment_method_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that billing is required.
	 */
	public function test_validate_required_order_fields_rejects_missing_billing() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['billing'] );

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.billing_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that shipping is required.
	 */
	public function test_validate_required_order_fields_rejects_missing_shipping() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['shipping'] );

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that shipping_lines is required.
	 */
	public function test_validate_required_order_fields_rejects_missing_shipping_lines() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['shipping_lines'] );

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_lines_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Test that valid top-level fields pass validation.
	 */
	public function test_validate_required_order_fields_accepts_minimal_valid_payload() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $api, $this->get_minimal_valid_payload() );

		$this->assertTrue( true === $result );
	}

	/**
	 * Creating an order through the Miguel create API must NOT queue a WooCommerce -> Miguel
	 * sync-back for that order. Miguel already owns the order (it triggered the creation), so
	 * syncing it back would create a duplicate order in Miguel with a different order code.
	 */
	public function test_create_order_does_not_queue_sync_back_to_miguel() {
		// Wire the real WooCommerce -> Miguel sync hooks so order saves would normally queue a sync.
		$orders = new Miguel_Orders(
			new Miguel_Hook_Manager(),
			new Miguel_V2_Client( 'https://example.com', 'test-token' )
		);
		$orders->register_hooks();

		$product = Miguel_Helper_Product::create_downloadable_product();

		$payload = array(
			'idempotency_key' => 'idem-' . $product->get_id(),
			'payment_method'  => 'cod',
			'billing'         => array(
				'first_name' => 'Test',
				'last_name'  => 'User',
				'email'      => 'buyer@example.com',
			),
			'shipping'        => array(
				'first_name' => 'Test',
				'last_name'  => 'User',
			),
			'shipping_lines'  => array(
				array(
					'method_id'    => 'flat_rate',
					'method_title' => 'Flat rate',
					'total'        => '0.00',
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

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Order creation should succeed.' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'], 'A WooCommerce order should have been created.' );

		// The just-created order must not have been queued for sync back to Miguel.
		$this->assertFalse(
			as_has_scheduled_action( Miguel_Orders::ASYNC_SYNC_ACTION, null, 'miguel' ),
			'Creating an order via the Miguel API must not queue a sync-back to Miguel.'
		);

		$orders->get_hook_manager()->remove_all_hooks();
		Miguel_Helper_Order::delete_order( $data['id'] );
	}
}
