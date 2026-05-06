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
	 * Test that helper email flags are not forwarded to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_strips_send_email_flags() {
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
		$product_two = Miguel_Helper_Product::create_downloadable_product();

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
	 * Test that invalid customer_id is rejected when provided.
	 */
	public function test_validate_required_order_fields_rejects_invalid_customer_id() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		$payload = $this->get_minimal_valid_payload();
		$payload['customer_id'] = 999999;

		$result = $method->invoke( $api, $payload );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.customer_id_invalid', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
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
}
