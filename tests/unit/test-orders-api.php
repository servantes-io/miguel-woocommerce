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

	public function test_get_order_line_items_include_all_products_with_miguel_code() {
		$created  = Miguel_Helper_Order::create_order_downloadable();
		$order_id = $created['order_id'];

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order_id );
		$request->set_param( 'id', $order_id );

		$data = $api->get_order( $request )->get_data();

		$this->assertArrayHasKey( 'line_items', $data );
		$this->assertNotEmpty( $data['line_items'] );

		foreach ( $data['line_items'] as $line ) {
			foreach ( array( 'product_id', 'name', 'sku', 'quantity', 'total', 'tax', 'code' ) as $key ) {
				$this->assertArrayHasKey( $key, $line );
			}
		}

		$codes = array_column( $data['line_items'], 'code' );
		$this->assertContains( 'dummy-name', $codes );                         // downloadable Miguel product
		$this->assertTrue( in_array( null, $codes, true ), 'Expected a line item with null code.' ); // virtual non-Miguel product
	}

	public function test_get_order_products_deduplicate_multiple_format_downloads() {
		// A single ebook product carries one download per format (epub, mobi) that
		// all share the same Miguel id, so it must appear only once in products.
		$created  = Miguel_Helper_Order::create_order_downloadable();
		$order_id = $created['order_id'];

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order_id );
		$request->set_param( 'id', $order_id );

		$data = $api->get_order( $request )->get_data();

		$codes            = array_column( $data['products'], 'code' );
		$dummy_name_count = count( array_keys( $codes, 'dummy-name', true ) );

		$this->assertSame(
			1,
			$dummy_name_count,
			'Expected the multi-format ebook to appear once, got ' . $dummy_name_count . ' duplicate product entries.'
		);
	}

	public function test_get_order_includes_totals() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		foreach ( array( 'total', 'subtotal', 'total_tax', 'shipping_total', 'discount_total' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
			$this->assertIsString( $data[ $key ] );
		}
	}

	public function test_get_order_includes_structured_addresses() {
		$order = Miguel_Helper_Order::create_order();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertArrayHasKey( 'billing', $data );
		$this->assertArrayHasKey( 'shipping', $data );

		$billing = $data['billing'];
		$this->assertSame( 'Jan', $billing['first_name'] );
		$this->assertSame( 'Miguel', $billing['last_name'] );
		$this->assertSame( 'CZ', $billing['country'] );
		$this->assertSame( 'Brno', $billing['city'] );
		$this->assertSame( '60200', $billing['postcode'] );
		$this->assertSame( 'test@melvil.cz', $billing['email'] );

		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['shipping'] );
		}
	}

	public function test_get_order_includes_payment_and_shipping_meta() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Direct Bank Transfer' );
		$order->set_customer_note( 'Leave at the door' );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertSame( 'bacs', $data['payment_method'] );
		$this->assertSame( 'Direct Bank Transfer', $data['payment_method_title'] );
		$this->assertSame( 'Leave at the door', $data['customer_note'] );
		$this->assertArrayHasKey( 'transaction_id', $data );
		$this->assertArrayHasKey( 'shipping_lines', $data );
		$this->assertIsArray( $data['shipping_lines'] );
	}

	public function test_get_order_shipping_lines_include_method_details() {
		$order = Miguel_Helper_Order::create_order();

		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_id( 'flat_rate' );
		$shipping_item->set_method_title( 'Flat Rate' );
		$shipping_item->set_total( '5.00' );
		$order->add_item( $shipping_item );
		$order->calculate_totals();
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertNotEmpty( $data['shipping_lines'] );

		$found = null;
		foreach ( $data['shipping_lines'] as $line ) {
			if ( 'flat_rate' === $line['method_id'] ) {
				$found = $line;
				break;
			}
		}

		$this->assertNotNull( $found, 'Expected shipping line not found.' );
		$this->assertSame( 'Flat Rate', $found['method_title'] );
		$this->assertSame( wc_format_decimal( '5.00' ), $found['total'] );
	}

	public function test_get_order_includes_print_code_for_physical_product() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'printed-book-9' );
		$product->save();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertContains( 'printed-book-9:print', array_column( $data['products'], 'code' ) );
		$this->assertContains( 'printed-book-9:print', array_column( $data['line_items'], 'code' ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * The reconciliation defect: Miguel deletes an order when the shop refunds it, then the
	 * periodic pull hands the same order back. Miguel finds no live row (the deleted one is
	 * filtered out of its lookup) and creates a fresh order with CreateTasks — regenerating the
	 * books and re-granting ownership, so the customer's access returns on its own.
	 *
	 * The pull therefore reports whether a status means the order is gone. Withholding such orders
	 * would fix the resurrection but remove the repair path: a delete whose push never arrived
	 * would leave the order alive in Miguel forever, with nothing to correct it.
	 */
	public function test_get_orders_flags_orders_whose_status_means_deleted() {
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array( 'refunded' ) );

		try {
			$live = Miguel_Helper_Order::create_order();
			$live->update_status( 'processing' );

			$refunded = Miguel_Helper_Order::create_order();
			$refunded->update_status( 'refunded' );

			$orders = $this->fetch_orders_by_id();

			$this->assertArrayHasKey( strval( $refunded->get_id() ), $orders,
				'a deleted-status order must still be reported, or a lost delete can never be repaired' );
			$this->assertTrue( $orders[ strval( $refunded->get_id() ) ]['deleted'] );

			$this->assertArrayHasKey( strval( $live->get_id() ), $orders );
			$this->assertFalse( $orders[ strval( $live->get_id() ) ]['deleted'] );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	/**
	 * The flag follows the setting rather than a hardcoded list, so a status the operator unticked
	 * keeps being reconciled as a normal order.
	 */
	public function test_get_orders_does_not_flag_a_status_the_operator_unticked() {
		update_option( Miguel_Orders::DELETED_STATUSES_OPTION, array( 'refunded' ) );

		try {
			$cancelled = Miguel_Helper_Order::create_order();
			$cancelled->update_status( 'cancelled' );

			$orders = $this->fetch_orders_by_id();

			$this->assertArrayHasKey( strval( $cancelled->get_id() ), $orders );
			$this->assertFalse( $orders[ strval( $cancelled->get_id() ) ]['deleted'] );
		} finally {
			delete_option( Miguel_Orders::DELETED_STATUSES_OPTION );
		}
	}

	/**
	 * The pull must not hand back a product the customer was refunded for, or Miguel would
	 * re-create the item the push just removed.
	 */
	public function test_get_order_products_leave_out_a_refunded_line() {
		$kept     = Miguel_Helper_Product::create_miguel_product( 'kept-book' );
		$refunded = Miguel_Helper_Product::create_miguel_product( 'refunded-book' );

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $kept, 1 );
		$refunded_item_id = $order->add_product( $refunded, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $refunded_item_id, 1, 10.00 );

		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders/' . $order->get_id() );
		$request->set_param( 'id', $order->get_id() );

		$data = $api->get_order( $request )->get_data();

		$this->assertSame( array( 'kept-book' ), array_column( $data['products'], 'code' ) );
		$this->assertFalse( $data['deleted'], 'one Miguel product is left, so the order stays' );
	}

	/**
	 * Mirrors the push: an order whose Miguel products were all refunded is reported as deleted,
	 * so the pull can repair a delete whose push never arrived.
	 */
	public function test_get_orders_flags_an_order_whose_miguel_products_were_all_refunded() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order   = Miguel_Helper_Order::create_order();
		$item_id = $order->add_product( $product, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $item_id, 0, 10.00 );

		$orders = $this->fetch_orders_by_id();
		$id     = strval( $order->get_id() );

		$this->assertArrayHasKey( $id, $orders );
		$this->assertSame( 'processing', $orders[ $id ]['status'] );
		$this->assertTrue( $orders[ $id ]['deleted'] );
		$this->assertSame( array(), $orders[ $id ]['products'] );
	}

	/**
	 * The pull must judge "everything refunded" the same way the push does: a bundle still counts
	 * as a live Miguel line as long as one of its bundled codes is un-refunded, even though the
	 * pull's own code source (unlike the mapper) does not see bundles at all.
	 */
	public function test_get_orders_does_not_flag_an_order_whose_bundle_still_has_codes_left() {
		$book1 = Miguel_Helper_Product::create_miguel_product( 'bundle-book-1' );
		$book2 = Miguel_Helper_Product::create_miguel_product( 'bundle-book-2' );

		$bundle = WC_Helper_Product::create_simple_product();
		$bundle->set_regular_price( '20' );
		$bundle->save();
		update_post_meta(
			$bundle->get_id(),
			'_bundle_ids',
			array(
				(string) $book1->get_id() => array(),
				(string) $book2->get_id() => array(),
			)
		);

		$ebook = Miguel_Helper_Product::create_miguel_product( 'loose-ebook' );

		$order          = Miguel_Helper_Order::create_order();
		$order->add_product( $bundle, 1 );
		$ebook_item_id  = $order->add_product( $ebook, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $ebook_item_id, 1, 10.00 );

		$orders = $this->fetch_orders_by_id();
		$id     = strval( $order->get_id() );

		$this->assertArrayHasKey( $id, $orders );
		$this->assertFalse( $orders[ $id ]['deleted'], 'the bundle still delivers codes, so the order stays' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * When the only Miguel product in the order is a bundle and its line is refunded, the pull
	 * must flag the order as deleted, mirroring the push's bundle-aware check.
	 */
	public function test_get_orders_flags_an_order_whose_only_miguel_product_is_a_refunded_bundle() {
		$book1 = Miguel_Helper_Product::create_miguel_product( 'solo-bundle-book-1' );
		$book2 = Miguel_Helper_Product::create_miguel_product( 'solo-bundle-book-2' );

		$bundle = WC_Helper_Product::create_simple_product();
		$bundle->set_regular_price( '20' );
		$bundle->save();
		update_post_meta(
			$bundle->get_id(),
			'_bundle_ids',
			array(
				(string) $book1->get_id() => array(),
				(string) $book2->get_id() => array(),
			)
		);

		$order          = Miguel_Helper_Order::create_order();
		$bundle_item_id = $order->add_product( $bundle, 1 );
		$order->calculate_totals( false );
		$order->save();

		Miguel_Helper_Order::refund_line( $order, $bundle_item_id, 1, 20.00 );

		$orders = $this->fetch_orders_by_id();
		$id     = strval( $order->get_id() );

		$this->assertArrayHasKey( $id, $orders );
		$this->assertTrue( $orders[ $id ]['deleted'], 'the bundle was the only Miguel product and it is fully refunded' );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Orders returned by the pull for everything modified since the epoch, keyed by id.
	 *
	 * @return array
	 */
	private function fetch_orders_by_id() {
		$api     = new Miguel_Orders_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/orders' );
		$request->set_param( 'updated_since', '1970-01-01T00:00:00Z' );

		$response = $api->get_orders( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$by_id = array();
		foreach ( $response->get_data()['orders'] as $order ) {
			$by_id[ $order['id'] ] = $order;
		}

		return $by_id;
	}
}
