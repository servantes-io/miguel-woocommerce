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
	 * Invoke a private method of the API.
	 *
	 * @param object $object      Object under test.
	 * @param string $method_name Method name.
	 * @param array  $args        Arguments.
	 * @return mixed
	 */
	private function invoke_private( $object, $method_name, array $args = array() ) {
		$method = ( new ReflectionClass( $object ) )->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $args );
	}

	/**
	 * A one-piece line item for a product. One key per line: WPCS rejects single-line
	 * associative arrays with more than one key.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function line_item( $product_id ) {
		return array(
			'product_id' => $product_id,
			'quantity'   => 1,
		);
	}

	/**
	 * Create a printed book: neither virtual nor downloadable, with an explicit SKU.
	 *
	 * @param string $sku Unique SKU.
	 * @return WC_Product
	 */
	private function create_printed_product( $sku ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( $sku );
		$product->save();

		return $product;
	}

	/**
	 * Create a virtual product that is not downloadable (e.g. a voucher), with an explicit SKU.
	 *
	 * @param string $sku Unique SKU.
	 * @return WC_Product
	 */
	private function create_virtual_product_without_download( $sku ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( true );
		$product->set_sku( $sku );
		$product->save();

		return $product;
	}

	/**
	 * The shipping the Miguel backend sends today with a digital-only order: a copy of the
	 * billing address and a zero-cost free_shipping line.
	 *
	 * @return array
	 */
	private function get_placeholder_shipping() {
		return array(
			'shipping'       => array(
				'first_name' => 'Jan Novák',
				'address_1'  => 'Václavské náměstí 1',
				'city'       => 'Praha',
				'postcode'   => '11000',
				'country'    => 'CZ',
			),
			'shipping_lines' => array(
				array(
					'method_id'    => 'free_shipping',
					'method_title' => 'Free Shipping',
					'total'        => '0.00',
				),
			),
		);
	}

	/**
	 * POST an order payload through the Miguel create API.
	 *
	 * @param array $payload Request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function post_order( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $request );
	}

	/**
	 * The payload the Miguel backend sends for a one-item mobile order, without shipping.
	 *
	 * @param int    $product_id      Product ID.
	 * @param string $idempotency_key Idempotency key.
	 * @return array
	 */
	private function get_digital_order_payload( $product_id, $idempotency_key ) {
		return array(
			'idempotency_key' => $idempotency_key,
			'payment_method'  => 'miguel',
			'user_email'      => 'buyer@example.com',
			'billing'         => array(
				'first_name' => 'Jan Novák',
				'address_1'  => 'Václavské náměstí 1',
				'city'       => 'Praha',
				'postcode'   => '11000',
				'country'    => 'CZ',
				'email'      => 'buyer@example.com',
			),
			'line_items'      => array(
				array(
					'product_id' => $product_id,
					'quantity'   => 1,
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
			array_merge(
				$this->get_placeholder_shipping(),
				array(
					'line_items' => array(
						array(
							'product_code' => 'printed-book-42:print',
							'quantity' => 1,
						),
					),
				)
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
	 * Test that order_note is not forwarded to WooCommerce.
	 */
	public function test_prepare_payload_for_wc_order_strips_order_note() {
		Miguel_Helper_Product::create_downloadable_product();
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$api,
			array(
				'order_note' => 'Objednávka vytvořena v aplikaci Melvil.',
				'line_items' => array(
					array(
						'product_code' => 'dummy-name',
						'quantity' => 1,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'order_note', $result );
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
	 * Test that a non-string order_note is rejected.
	 */
	public function test_validate_required_order_fields_rejects_non_string_order_note() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		foreach ( array( 42, true, array( 'note' ), array( 'text' => 'note' ) ) as $invalid_note ) {
			$payload = $this->get_minimal_valid_payload();
			$payload['order_note'] = $invalid_note;

			$result = $method->invoke( $api, $payload );

			$this->assertTrue( is_wp_error( $result ), 'A ' . gettype( $invalid_note ) . ' order_note must be rejected.' );
			$this->assertEquals( 'order.order_note_invalid', $result->get_error_code() );
			$this->assertEquals( 409, $result->get_error_data()['status'] );
			$this->assertEquals( 'order_note', $result->get_error_data()['field'] );
		}
	}

	/**
	 * Test that a string or null order_note passes validation.
	 */
	public function test_validate_required_order_fields_accepts_string_or_null_order_note() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$reflection = new ReflectionClass( $api );
		$method = $reflection->getMethod( 'validate_required_order_fields' );
		$method->setAccessible( true );

		foreach ( array( 'Objednávka vytvořena v aplikaci Melvil.', '', null ) as $valid_note ) {
			$payload = $this->get_minimal_valid_payload();
			$payload['order_note'] = $valid_note;

			$this->assertTrue( true === $method->invoke( $api, $payload ) );
		}
	}

	/**
	 * A request with an invalid order_note must not create an order.
	 */
	public function test_create_order_with_non_string_order_note_creates_no_order() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$orders_before = wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) );

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => array( 'not', 'a', 'string' ) ) ) );

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertEquals( 'order.order_note_invalid', $response->get_error_code() );
		$this->assertEquals( 409, $response->get_error_data()['status'] );
		$this->assertCount( count( $orders_before ), wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );
	}

	/**
	 * A string order_note becomes one private, system-authored note on the order.
	 */
	public function test_create_order_adds_order_note_as_private_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$text    = 'Objednávka vytvořena v aplikaci Melvil.';

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => $text ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$order_id = $response->get_data()['id'];

		$notes = $this->get_order_notes_by_content( $order_id );
		$this->assertArrayHasKey( $text, $notes, 'The order should carry the note Miguel sent.' );
		$this->assertFalse( $notes[ $text ]->customer_note, 'The note must be private, not a customer note.' );
		$this->assertSame( 'system', $notes[ $text ]->added_by );

		// Not forwarded to WooCommerce as anything else.
		$order = wc_get_order( $order_id );
		$this->assertSame( '', $order->get_customer_note() );
		$this->assertSame( '', $order->get_meta( 'order_note' ) );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	/**
	 * The note is sanitized like post content: scripts go, basic inline HTML stays.
	 */
	public function test_create_order_sanitizes_order_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order(
			$this->build_order_request(
				$product->get_id(),
				array( 'order_note' => '<script>alert(1)</script>Objednávka z aplikace <strong>Melvil</strong>.' )
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$order_id = $response->get_data()['id'];

		$matching = array_filter(
			array_keys( $this->get_order_notes_by_content( $order_id ) ),
			function ( $content ) {
				return false !== strpos( $content, '<strong>Melvil</strong>' );
			}
		);
		$this->assertCount( 1, $matching, 'The sanitized note should be on the order, <strong> kept.' );
		$this->assertStringNotContainsString( '<script', reset( $matching ) );

		Miguel_Helper_Order::delete_order( $order_id );
	}

	/**
	 * Absent, null, blank and markup-only notes add nothing: the order ends up with
	 * exactly the notes of an identical order sent without the field.
	 */
	public function test_create_order_without_usable_order_note_adds_no_note() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$control  = $api->create_order( $this->build_order_request( $product->get_id() ) );
		$expected = count( $this->get_order_notes_by_content( $control->get_data()['id'] ) );

		foreach ( array( null, '', '   ', '<script></script>' ) as $unusable_note ) {
			$response = $api->create_order( $this->build_order_request( $product->get_id(), array( 'order_note' => $unusable_note ) ) );

			$this->assertSame( 201, $response->get_status() );
			$this->assertCount(
				$expected,
				$this->get_order_notes_by_content( $response->get_data()['id'] ),
				'An order_note of ' . wp_json_encode( $unusable_note ) . ' must not add a note.'
			);

			Miguel_Helper_Order::delete_order( $response->get_data()['id'] );
		}

		Miguel_Helper_Order::delete_order( $control->get_data()['id'] );
	}

	/**
	 * Replaying the same request returns the existing order and adds no second note.
	 */
	public function test_create_order_replay_does_not_duplicate_order_note() {
		$product   = Miguel_Helper_Product::create_downloadable_product();
		$text      = 'Objednávka vytvořena v aplikaci Miguel.';
		$overrides = array(
			'idempotency_key' => 'order-note-replay-' . $product->get_id(),
			'order_note'      => $text,
		);

		$api    = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$first  = $api->create_order( $this->build_order_request( $product->get_id(), $overrides ) );
		$replay = $api->create_order( $this->build_order_request( $product->get_id(), $overrides ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['idempotent_replay'] );
		$this->assertSame( $first->get_data()['id'], $replay->get_data()['id'] );

		// Count raw notes: get_order_notes_by_content() keys by text and would hide a duplicate.
		$with_text = array_filter(
			wc_get_order_notes( array( 'order_id' => $first->get_data()['id'] ) ),
			function ( $note ) use ( $text ) {
				return $text === $note->content;
			}
		);
		$this->assertCount( 1, $with_text );

		Miguel_Helper_Order::delete_order( $first->get_data()['id'] );
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

	/**
	 * The real-eshop scenario: with NO print suffix configured, a printed
	 * (non-downloadable) product with SKU "musk" ordered by its bare
	 * product_code "musk" resolves to that product and the order is created.
	 */
	public function test_create_order_for_printed_book_musk_succeeds_by_bare_sku_when_no_suffix() {
		$print = WC_Helper_Product::create_simple_product();
		$print->set_downloadable( false );
		$print->set_virtual( false );
		$print->set_sku( 'musk' );
		$print->save();

		$request = $this->build_musk_order_request( 'musk' );

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Order creation should succeed.' );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );

		$order       = wc_get_order( $data['id'] );
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = $item->get_product_id();
		}
		$this->assertContains( $print->get_id(), $product_ids, 'Bare "musk" should resolve to the printed product.' );

		Miguel_Helper_Order::delete_order( $data['id'] );
	}

	/**
	 * The correct printed-book flow: with the suffix configured, the printed
	 * product SKU "musk" is addressable as "musk:print" and the order is
	 * created successfully, resolving to the printed product.
	 */
	public function test_create_order_for_printed_book_musk_succeeds_with_suffixed_code() {
		add_filter(
			'miguel_print_code_suffix',
			function () {
				return ':print';
			}
		);

		$print = WC_Helper_Product::create_simple_product();
		$print->set_downloadable( false );
		$print->set_virtual( false );
		$print->set_sku( 'musk' );
		$print->save();

		$request = $this->build_musk_order_request( 'musk:print' );

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Order creation should succeed.' );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertGreaterThan( 0, $data['id'] );

		$order = wc_get_order( $data['id'] );
		$this->assertInstanceOf( WC_Order::class, $order );

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = $item->get_product_id();
		}
		$this->assertContains( $print->get_id(), $product_ids, 'Order line item should resolve to the printed "musk" product.' );

		Miguel_Helper_Order::delete_order( $data['id'] );
	}

	/**
	 * Put the Miguel gateway in WooCommerce's list the way a shop has it.
	 *
	 * Miguel_Test_Case::setUp() resets the plugin instance, which removes its hooks, the
	 * woocommerce_payment_gateways filter included. Re-creating the instance re-adds it, and
	 * re-initialising the gateways re-reads woocommerce_miguel_settings.
	 */
	private function load_payment_gateways() {
		Miguel::instance();
		WC()->payment_gateways()->init();
	}

	/**
	 * Run prepare_payload_for_wc_order() on a payload.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	private function prepare_payload( $payload ) {
		$api    = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$method = ( new ReflectionClass( $api ) )->getMethod( 'prepare_payload_for_wc_order' );
		$method->setAccessible( true );

		return $method->invoke( $api, $payload );
	}

	/**
	 * A payload for one downloadable product, paid with the given method.
	 *
	 * @param string $payment_method Payment method id.
	 * @return array
	 */
	private function get_payload_paid_with( $payment_method ) {
		$product = Miguel_Helper_Product::create_downloadable_product();

		return array(
			'payment_method' => $payment_method,
			'line_items'     => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 1,
				),
			),
		);
	}

	/**
	 * Miguel sends payment_method "miguel" with no title; the gateway's title fills it in.
	 */
	public function test_prepare_payload_for_wc_order_fills_title_of_miguel_payment_method() {
		$this->load_payment_gateways();

		$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Miguel', $result['payment_method_title'] );
	}

	/**
	 * A blank title counts as none.
	 */
	public function test_prepare_payload_for_wc_order_fills_blank_title_of_miguel_payment_method() {
		$this->load_payment_gateways();

		$payload                         = $this->get_payload_paid_with( 'miguel' );
		$payload['payment_method_title'] = '   ';

		$result = $this->prepare_payload( $payload );

		$this->assertSame( 'Miguel', $result['payment_method_title'] );
	}

	/**
	 * A title Miguel sends is kept, so it can word it per app.
	 */
	public function test_prepare_payload_for_wc_order_keeps_title_sent_with_miguel_payment_method() {
		$this->load_payment_gateways();

		$payload                         = $this->get_payload_paid_with( 'miguel' );
		$payload['payment_method_title'] = 'Melvil app';

		$result = $this->prepare_payload( $payload );

		$this->assertSame( 'Melvil app', $result['payment_method_title'] );
	}

	/**
	 * Other payment methods are left exactly as sent.
	 */
	public function test_prepare_payload_for_wc_order_leaves_other_payment_methods_alone() {
		$this->load_payment_gateways();

		$result = $this->prepare_payload( $this->get_payload_paid_with( 'bacs' ) );

		$this->assertArrayNotHasKey( 'payment_method_title', $result );
	}

	/**
	 * A merchant who renamed the gateway gets that name on new orders.
	 */
	public function test_prepare_payload_for_wc_order_uses_renamed_gateway_title() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Zaplaceno v aplikaci',
				'description' => '',
			)
		);
		$this->load_payment_gateways();

		try {
			$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

			$this->assertSame( 'Zaplaceno v aplikaci', $result['payment_method_title'] );
		} finally {
			delete_option( 'woocommerce_miguel_settings' );
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * With the gateway gone from WooCommerce's list (e.g. filtered out), the title is still "Miguel".
	 */
	public function test_prepare_payload_for_wc_order_falls_back_when_gateway_is_not_registered() {
		$this->load_payment_gateways();
		WC()->payment_gateways()->payment_gateways = array();

		try {
			$result = $this->prepare_payload( $this->get_payload_paid_with( 'miguel' ) );

			$this->assertSame( 'Miguel', $result['payment_method_title'] );
		} finally {
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * End to end: the created order stores the title, so emails and the order list show it.
	 */
	public function test_create_order_with_miguel_payment_method_stores_the_gateway_title() {
		$this->load_payment_gateways();
		$product = Miguel_Helper_Product::create_downloadable_product();

		$payload = array(
			'idempotency_key' => 'miguel-title-' . $product->get_id(),
			'payment_method'  => 'miguel',
			'billing'         => array(
				'first_name' => 'Test',
				'email'      => 'buyer@example.com',
			),
			'shipping'        => array(
				'first_name' => 'Test',
			),
			'shipping_lines'  => array(
				array(
					'method_id' => 'free_shipping',
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

		$api      = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );
		$response = $api->create_order( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertSame( 'miguel', $order->get_payment_method() );
		$this->assertSame( 'Miguel', $order->get_payment_method_title() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Build the real-eshop order request for the "musk" printed book, varying
	 * only the line-item product_code.
	 *
	 * @param string $product_code Product code to send in the single line item.
	 * @return WP_REST_Request
	 */
	private function build_musk_order_request( $product_code ) {
		$payload = array(
			'idempotency_key' => 'musk-' . $product_code,
			'payment_method'  => 'miguel',
			'user_email'      => 'roman@kriz.io',
			'currency'        => 'CZK',
			'send_emails'     => false,
			'billing'         => array(
				'first_name' => 'Roman Kriz',
				'address_1'  => 'Sivice 254',
				'city'       => 'sivice',
				'postcode'   => '66407',
				'country'    => 'CZ',
				'email'      => 'roman@kriz.io',
				'phone'      => '+420723276729',
			),
			'shipping'        => array(
				'first_name' => 'Roman Kriz',
				'address_1'  => 'Sivice 254',
				'city'       => 'sivice',
				'postcode'   => '66407',
				'country'    => 'CZ',
			),
			'shipping_lines'  => array(
				array(
					'method_id'    => 'wc_zasilkovna',
					'method_title' => 'Zásilkovna',
				),
			),
			'line_items'      => array(
				array(
					'product_code' => $product_code,
					'quantity'     => 1,
					'subtotal'     => '373.00',
					'total'        => '373.00',
				),
			),
		);

		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	/**
	 * Build an order-create request for one unit of the given product.
	 *
	 * The shipping block is always present so the request stays valid whatever the
	 * plugin requires of shipping; each call gets its own idempotency key unless the
	 * overrides set one.
	 *
	 * @param int   $product_id WooCommerce product ID.
	 * @param array $overrides  Top-level payload fields to add or replace.
	 * @return WP_REST_Request
	 */
	private function build_order_request( $product_id, $overrides = array() ) {
		$payload = array_merge(
			array(
				'idempotency_key' => 'order-note-' . wp_generate_uuid4(),
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
						'product_id' => $product_id,
						'quantity'   => 1,
					),
				),
			),
			$overrides
		);

		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return $request;
	}

	/**
	 * All notes of an order, keyed by their text.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Note objects as returned by wc_get_order_notes(), keyed by content.
	 */
	private function get_order_notes_by_content( $order_id ) {
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order_id ) ) as $note ) {
			$notes[ $note->content ] = $note;
		}

		return $notes;
	}

	/**
	 * An e-book (virtual + downloadable) needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_downloadable_product() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $ebook->get_id() ) ) ) )
		);
	}

	/**
	 * A downloadable product whose "virtual" box nobody ticked still needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_downloadable_product_that_is_not_virtual() {
		$ebook = WC_Helper_Product::create_simple_product();
		$ebook->set_downloadable( true );
		$ebook->set_virtual( false );
		$ebook->set_sku( 'smp14-downloadable-not-virtual' );
		$ebook->save();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $ebook->get_id() ) ) ) )
		);
	}

	/**
	 * A virtual product that is not downloadable (e.g. a voucher) needs no delivery.
	 */
	public function test_order_needs_delivery_is_false_for_virtual_product() {
		$voucher = $this->create_virtual_product_without_download( 'smp14-voucher' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $voucher->get_id() ) ) ) )
		);
	}

	/**
	 * A printed book needs delivery.
	 */
	public function test_order_needs_delivery_is_true_for_printed_product() {
		$printed = $this->create_printed_product( 'smp14-printed' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( $printed->get_id() ) ) ) )
		);
	}

	/**
	 * One printed book among e-books makes the whole order need delivery.
	 */
	public function test_order_needs_delivery_is_true_for_mixed_order() {
		$printed = $this->create_printed_product( 'smp14-printed-mixed' );
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array(
					array(
						$this->line_item( $ebook->get_id() ),
						$this->line_item( $printed->get_id() ),
					),
				)
			)
		);
	}

	/**
	 * A product that cannot be loaded counts as needing delivery.
	 */
	public function test_order_needs_delivery_is_true_for_unknown_product() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertTrue(
			$this->invoke_private( $api, 'order_needs_delivery', array( array( $this->line_item( 999999 ) ) ) )
		);
	}

	/**
	 * With a variation_id the variation decides, not its parent.
	 */
	public function test_order_needs_delivery_uses_the_variation_when_given() {
		$variable     = WC_Helper_Product::create_variation_product();
		$variation_id = $variable->get_children()[0];
		$variation    = wc_get_product( $variation_id );
		$variation->set_virtual( true );
		$variation->save();

		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$this->assertFalse(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array( array( array_merge( $this->line_item( $variable->get_id() ), array( 'variation_id' => $variation_id ) ) ) )
			),
			'A virtual variation needs no delivery.'
		);
		$this->assertTrue(
			$this->invoke_private(
				$api,
				'order_needs_delivery',
				array( array( $this->line_item( $variable->get_id() ) ) )
			),
			'Without variation_id the non-virtual parent decides.'
		);
	}

	/**
	 * Today's backend payload for an e-book: the placeholder shipping is dropped.
	 */
	public function test_prepare_payload_drops_free_shipping_from_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A digital-only order may omit shipping altogether.
	 */
	public function test_prepare_payload_accepts_digital_only_order_without_shipping() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$result = $this->invoke_private(
			$api,
			'prepare_payload_for_wc_order',
			array( array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) ) )
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A shipping line without a total counts as free.
	 */
	public function test_prepare_payload_drops_shipping_line_without_total_from_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array( array( 'method_id' => 'free_shipping' ) );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertArrayNotHasKey( 'shipping', $result );
		$this->assertArrayNotHasKey( 'shipping_lines', $result );
	}

	/**
	 * A digital-only order whose shipping line has a cost keeps it, and its address, as sent.
	 */
	public function test_prepare_payload_keeps_paid_shipping_on_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id' => 'flat_rate',
				'total'     => '49.00',
			),
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertSame( $payload['shipping'], $result['shipping'] );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * A total that is not a number counts as a cost: the line is kept for WooCommerce to judge.
	 */
	public function test_prepare_payload_keeps_shipping_line_with_non_numeric_total_on_digital_only_order() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$api   = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload                   = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $ebook->get_id() ) ) )
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id' => 'flat_rate',
				'total'     => 'free',
			),
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertArrayHasKey( 'shipping', $result );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * A printed book still requires a shipping address.
	 */
	public function test_prepare_payload_rejects_printed_order_without_shipping() {
		$printed = $this->create_printed_product( 'smp14-printed-no-address' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $printed->get_id() ) ) )
		);
		unset( $payload['shipping'] );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 'shipping', $result->get_error_data()['field'] );
	}

	/**
	 * A printed book still requires shipping lines.
	 */
	public function test_prepare_payload_rejects_printed_order_without_shipping_lines() {
		$printed = $this->create_printed_product( 'smp14-printed-no-lines' );
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array( 'line_items' => array( $this->line_item( $printed->get_id() ) ) )
		);
		unset( $payload['shipping_lines'] );

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_lines_required', $result->get_error_code() );
		$this->assertEquals( 409, $result->get_error_data()['status'] );
		$this->assertEquals( 'shipping_lines', $result->get_error_data()['field'] );
	}

	/**
	 * An order with a printed book keeps its shipping exactly as sent, zero-cost lines included.
	 */
	public function test_prepare_payload_keeps_shipping_on_mixed_order() {
		$printed = $this->create_printed_product( 'smp14-printed-mixed-payload' );
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$api     = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = array_merge(
			$this->get_placeholder_shipping(),
			array(
				'line_items' => array(
					$this->line_item( $ebook->get_id() ),
					$this->line_item( $printed->get_id() ),
				),
			)
		);

		$result = $this->invoke_private( $api, 'prepare_payload_for_wc_order', array( $payload ) );

		$this->assertSame( $payload['shipping'], $result['shipping'] );
		$this->assertSame( $payload['shipping_lines'], $result['shipping_lines'] );
	}

	/**
	 * An unknown product counts as needing delivery, so the old validation applies.
	 */
	public function test_prepare_payload_rejects_unknown_product_without_shipping() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$result = $this->invoke_private(
			$api,
			'prepare_payload_for_wc_order',
			array( array( 'line_items' => array( $this->line_item( 999999 ) ) ) )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertEquals( 'order.shipping_required', $result->get_error_code() );
	}

	/**
	 * Top-level validation no longer asks for shipping: it cannot know yet whether the order ships.
	 */
	public function test_validate_required_order_fields_accepts_payload_without_shipping() {
		$api = new Miguel_Order_Create_Api( new Miguel_Hook_Manager() );

		$payload = $this->get_minimal_valid_payload();
		unset( $payload['shipping'], $payload['shipping_lines'] );

		$this->assertTrue( true === $this->invoke_private( $api, 'validate_required_order_fields', array( $payload ) ) );
	}

	/**
	 * Today's backend payload for an e-book creates an order with no shipping at all.
	 */
	public function test_create_order_digital_only_has_no_shipping() {
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$payload = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital' ), $this->get_placeholder_shipping() );

		$response = $this->post_order( $payload );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 0, $order->get_shipping_methods(), 'No shipping line may be stored.' );
		$this->assertSame( '', $order->get_shipping_address_1() );
		$this->assertSame( '', $order->get_shipping_first_name() );
		$this->assertFalse( $order->has_shipping_address() );
		$this->assertFalse( $order->needs_shipping_address() );
		$this->assertSame( 'Václavské náměstí 1', $order->get_billing_address_1(), 'Billing stays.' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A digital-only order without any shipping is accepted (it was a 409 before).
	 */
	public function test_create_order_accepts_digital_only_order_without_shipping() {
		$ebook = Miguel_Helper_Product::create_downloadable_product();

		$response = $this->post_order( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital-bare' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 0, $order->get_shipping_methods() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A digital-only order with a paid shipping line keeps it, so the total matches the payment.
	 */
	public function test_create_order_digital_only_keeps_paid_shipping() {
		$ebook                     = Miguel_Helper_Product::create_downloadable_product();
		$payload                   = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-digital-paid' ), $this->get_placeholder_shipping() );
		$payload['shipping_lines'] = array(
			array(
				'method_id'    => 'flat_rate',
				'method_title' => 'Flat rate',
				'total'        => '49.00',
			),
		);

		$response = $this->post_order( $payload );

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 1, $order->get_shipping_methods() );
		$this->assertEquals( 49.0, (float) $order->get_shipping_total() );
		$this->assertGreaterThanOrEqual( 49.0, (float) $order->get_total() );
		$this->assertTrue( $order->has_shipping_address() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * An order with a printed book keeps its shipping line and address.
	 */
	public function test_create_order_mixed_keeps_shipping() {
		$printed                   = $this->create_printed_product( 'smp14-printed-e2e' );
		$ebook                     = Miguel_Helper_Product::create_downloadable_product();
		$payload                   = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-mixed' ), $this->get_placeholder_shipping() );
		$payload['line_items'][]   = array(
			'product_id' => $printed->get_id(),
			'quantity'   => 1,
		);
		$payload['shipping_lines'] = array(
			array(
				'method_id'    => 'flat_rate',
				'method_title' => 'Flat rate',
				'total'        => '89.00',
			),
		);

		$response = $this->post_order( $payload );

		$this->assertSame( 201, $response->get_status() );

		$order = wc_get_order( $response->get_data()['id'] );
		$this->assertCount( 1, $order->get_shipping_methods() );
		$this->assertSame( 'Václavské náměstí 1', $order->get_shipping_address_1() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * A retried digital-only request replays the order it created (the hash covers the payload as sent).
	 */
	public function test_create_order_digital_only_replays_idempotently() {
		$ebook   = Miguel_Helper_Product::create_downloadable_product();
		$payload = array_merge( $this->get_digital_order_payload( $ebook->get_id(), 'smp14-replay' ), $this->get_placeholder_shipping() );

		$first  = $this->post_order( $payload );
		$second = $this->post_order( $payload );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['idempotent_replay'] );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'] );

		Miguel_Helper_Order::delete_order( $first->get_data()['id'] );
	}
}
