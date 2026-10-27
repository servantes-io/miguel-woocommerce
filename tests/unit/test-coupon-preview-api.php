<?php
/**
 * Tests for POST /miguel/v1/coupons/preview.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Coupon_Preview_Api extends Miguel_Test_Case {

	/**
	 * A product whose Miguel code is its bare SKU (no printed-book suffix is configured).
	 *
	 * @param string $sku   Unique SKU, which is also the product code.
	 * @param string $price Regular price.
	 * @return WC_Product
	 */
	private function create_product( $sku, $price = '319' ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->save();

		return $product;
	}

	/**
	 * A published coupon.
	 *
	 * @param string $code  Coupon code.
	 * @param array  $props WC_Coupon props to set, e.g. array( 'discount_type' => 'percent' ).
	 * @return WC_Coupon
	 */
	private function create_coupon( $code, $props = array() ) {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_props(
			array_merge(
				array(
					'discount_type' => 'percent',
					'amount'        => 20,
				),
				$props
			)
		);
		$coupon->save();

		return $coupon;
	}

	/**
	 * One line of the request.
	 *
	 * @param string $product_code Product code.
	 * @param int    $quantity     Quantity.
	 * @return array
	 */
	private function line( $product_code, $quantity = 1 ) {
		return array(
			'product_code' => $product_code,
			'quantity'     => $quantity,
		);
	}

	/**
	 * A request for one line of BOOK-A.
	 *
	 * @param string $code Coupon code.
	 * @param array  $more Extra request keys.
	 * @return array
	 */
	private function body( $code, $more = array() ) {
		return array_merge(
			array(
				'code'       => $code,
				'email'      => 'buyer@example.com',
				'line_items' => array( $this->line( 'BOOK-A' ) ),
			),
			$more
		);
	}

	/**
	 * POST a body to the route's callback.
	 *
	 * @param array $body  Request body.
	 * @param mixed $probe Probe double, or null for the real one.
	 * @return WP_REST_Response|WP_Error
	 */
	private function preview( array $body, $probe = null ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/coupons/preview' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return ( new Miguel_Coupon_Preview_Api( new Miguel_Hook_Manager(), null, $probe ) )->preview_coupon( $request );
	}

	/**
	 * The data of a 200 response.
	 *
	 * @param mixed $response Route response.
	 * @return array
	 */
	private function data( $response ) {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with(
				'rest_api_init',
				$this->isType( 'array' )
			);

		$api = new Miguel_Coupon_Preview_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_route_is_registered_and_validate_is_gone() {
		$api = new Miguel_Coupon_Preview_Api( new Miguel_Hook_Manager() );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/miguel/v1/coupons/preview', $routes );
		$this->assertArrayNotHasKey( '/miguel/v1/coupons/validate', $routes );

		$route = $routes['/miguel/v1/coupons/preview'][0];
		$this->assertArrayHasKey( 'POST', $route['methods'] );
		$this->assertSame( array( $api, 'validate_api_access' ), $route['permission_callback'] );
	}

	public function test_route_refuses_a_request_without_the_api_key() {
		$api = new Miguel_Coupon_Preview_Api( new Miguel_Hook_Manager() );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/miguel/v1/coupons/preview' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_applied_percent_coupon_response_shape() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		$data = $this->data( $this->preview( $this->body( 'c20' ) ) );

		$this->assertSame( 'c20', $data['code'] );
		$this->assertTrue( $data['applied'] );
		$this->assertNull( $data['error_code'] );
		$this->assertSame( 'percent', $data['coupon']['discount_type'] );
		$this->assertEquals( 319, $data['lines'][0]['before']['total'] );
		$this->assertEquals( 255.2, $data['lines'][0]['after']['total'] );
		$this->assertEquals( 63.8, $data['lines'][0]['discount'] );
		$this->assertTrue( $data['lines'][0]['discounted'] );
		$this->assertSame( 'BOOK-A', $data['lines'][0]['product_code'] );
		$this->assertSame( 1, $data['lines'][0]['quantity'] );
		$this->assertSame( 1, $data['lines'][0]['in_cart_quantity'] );
		$this->assertSame( array(), $data['added_lines'] );
		$this->assertSame( array(), $data['removed_lines'] );
		$this->assertNull( $data['gift_choices'] );
		$this->assertEquals( 255.2, $data['totals']['after']['total'] );
		$this->assertEquals( 319, $data['totals']['before']['total'] );
		$this->assertSame( array(), $data['other_coupons'] );
		$this->assertSame( array(), $data['unchecked'] );
		$this->assertSame( get_woocommerce_currency(), $data['currency'] );
	}

	/**
	 * Run a callback in the state of a REST request: WooCommerce loads no cart, session or customer
	 * there, and no cart has been loaded from a session yet.
	 *
	 * @param callable $work Callback.
	 * @return array array( callback result, array( WC()->cart, WC()->session, WC()->customer ) after it ).
	 */
	private function in_rest_request_state( callable $work ) {
		global $wp_actions;

		$wc     = WC();
		$saved  = array( $wc->cart, $wc->session, $wc->customer );
		$loaded = $wp_actions['woocommerce_load_cart_from_session'] ?? null;

		$wc->cart     = null;
		$wc->session  = null;
		$wc->customer = null;
		unset( $wp_actions['woocommerce_load_cart_from_session'] );

		try {
			$result = $work();

			return array( $result, array( $wc->cart, $wc->session, $wc->customer ) );
		} finally {
			list( $wc->cart, $wc->session, $wc->customer ) = $saved;
			if ( null !== $loaded ) {
				$wp_actions['woocommerce_load_cart_from_session'] = $loaded; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	public function test_preview_without_a_cart_session_or_customer() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		list( $response, $after ) = $this->in_rest_request_state(
			function () {
				return $this->preview( $this->body( 'c20' ) );
			}
		);
		$data = $this->data( $response );

		$this->assertTrue( $data['applied'] );
		$this->assertNull( $data['error_code'] );
		$this->assertEquals( 319, $data['lines'][0]['before']['total'] );
		$this->assertEquals( 255.2, $data['lines'][0]['after']['total'] );
		$this->assertEquals( 319, $data['totals']['before']['total'] );
		$this->assertEquals( 255.2, $data['totals']['after']['total'] );
		$this->assertSame( array( null, null, null ), $after );
	}

	public function test_registered_customers_saved_cart_flag_survives() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'buyer@example.com' ) );
		update_user_meta( $user_id, '_woocommerce_load_saved_cart_after_login', 1 );
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		list( $response ) = $this->in_rest_request_state(
			function () {
				return $this->preview( $this->body( 'c20' ) );
			}
		);

		$this->assertTrue( $this->data( $response )['applied'] );
		$this->assertSame( '1', get_user_meta( $user_id, '_woocommerce_load_saved_cart_after_login', true ) );
	}

	public function test_tax_without_an_address_is_the_web_cart_tax() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_default_country', 'CZ' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_default_customer_address', 'base' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'CZ',
				'tax_rate'         => '21.0000',
				'tax_rate_name'    => 'VAT',
				'tax_rate_class'   => '',
			)
		);
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		$data = $this->data( $this->preview( $this->body( 'c20' ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertEquals( 319, $data['lines'][0]['before']['total'] );
		$this->assertEquals( 55.36, $data['lines'][0]['before']['tax'] );
		$this->assertEquals( 55.36, $data['totals']['before']['tax'] );
		$this->assertEquals( 255.2, $data['lines'][0]['after']['total'] );
		$this->assertEquals( 255.2, $data['totals']['after']['total'] );
	}

	public function test_notices_of_both_calculations_without_repeats() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );
		$carts = 0;
		add_action(
			'woocommerce_add_to_cart',
			function () use ( &$carts ) {
				++$carts;
				wc_add_notice( 'Every cart', 'notice' );
				wc_add_notice( 'Cart ' . $carts, 'notice' );
			}
		);

		$data = $this->data( $this->preview( $this->body( 'c20' ) ) );

		$this->assertSame( array( 'Every cart', 'Cart 1', 'Cart 2', 'Coupon code applied successfully.' ), $data['notices'] );
	}

	public function test_refused_coupon_is_200_with_the_cart() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'old', array( 'date_expires' => time() - DAY_IN_SECONDS ) );

		$data = $this->data( $this->preview( $this->body( 'old' ) ) );

		$this->assertFalse( $data['applied'] );
		$this->assertSame( WC_Coupon::E_WC_COUPON_EXPIRED, $data['error_code'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertSame( $data['lines'][0]['before'], $data['lines'][0]['after'] );
		$this->assertFalse( $data['lines'][0]['discounted'] );
	}

	public function test_unknown_coupon_has_null_coupon() {
		$this->create_product( 'BOOK-A' );

		$data = $this->data( $this->preview( $this->body( 'nosuchcode' ) ) );

		$this->assertFalse( $data['applied'] );
		$this->assertNull( $data['coupon'] );
		$this->assertSame( WC_Coupon::E_WC_COUPON_NOT_EXIST, $data['error_code'] );
	}

	public function test_code_is_normalised() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		$data = $this->data( $this->preview( $this->body( ' C20 ' ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( 'C20', $data['code'] );
		$this->assertSame( 'c20', $data['coupon']['code'] );
	}

	public function test_free_shipping_flag() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'ship', array( 'free_shipping' => true ) );

		$data = $this->data( $this->preview( $this->body( 'ship' ) ) );

		$this->assertTrue( $data['coupon']['free_shipping'] );
		$this->assertNull( $data['totals']['after']['shipping'] );
	}

	/**
	 * A request for one BOOK-A and two BOOK-B.
	 *
	 * @param string $code Coupon code.
	 * @return array
	 */
	private function two_line_body( $code ) {
		return $this->body( $code, array( 'line_items' => array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B', 2 ) ) ) );
	}

	/**
	 * A product category.
	 *
	 * @param string $name Category name.
	 * @return int Term ID.
	 */
	private function create_category( $name ) {
		return (int) wp_insert_term( $name, 'product_cat' )['term_id'];
	}

	public function test_category_rules_leave_other_lines_undiscounted() {
		$fiction = $this->create_category( 'Fiction' );
		$poetry  = $this->create_category( 'Poetry' );
		foreach ( array(
			'BOOK-A' => $fiction,
			'BOOK-B' => $poetry,
		) as $sku => $category ) {
			$product = $this->create_product( $sku );
			$product->set_category_ids( array( $category ) );
			$product->save();
		}
		$this->create_coupon( 'fiction', array( 'product_categories' => array( $fiction ) ) );
		$this->create_coupon( 'noverse', array( 'excluded_product_categories' => array( $poetry ) ) );

		foreach ( array( 'fiction', 'noverse' ) as $code ) {
			$data = $this->data( $this->preview( $this->two_line_body( $code ) ) );

			$this->assertTrue( $data['applied'], $code );
			$this->assertTrue( $data['lines'][0]['discounted'], $code );
			$this->assertEquals( 255.2, $data['lines'][0]['after']['total'], $code );
			$this->assertFalse( $data['lines'][1]['discounted'], $code );
			$this->assertEquals( 0, $data['lines'][1]['discount'], $code );
			$this->assertEquals( 638, $data['lines'][1]['after']['total'], $code );
		}
	}

	public function test_sale_items_excluded_by_the_coupon_are_not_discounted() {
		$this->create_product( 'BOOK-A' );
		$sale = $this->create_product( 'BOOK-B' );
		$sale->set_sale_price( '299' );
		$sale->save();
		$this->create_coupon( 'nosale', array( 'exclude_sale_items' => true ) );

		$data = $this->data( $this->preview( $this->two_line_body( 'nosale' ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertTrue( $data['lines'][0]['discounted'] );
		$this->assertFalse( $data['lines'][1]['discounted'] );
		$this->assertEquals( 598, $data['lines'][1]['after']['total'] );
	}

	public function test_line_the_coupon_drops_is_a_removed_line() {
		$this->create_product( 'BOOK-A' );
		$dropped = $this->create_product( 'BOOK-B' )->get_id();
		$this->create_coupon( 'c20' );
		add_action(
			'woocommerce_applied_coupon',
			function () use ( $dropped ) {
				foreach ( WC()->cart->get_cart() as $key => $item ) {
					if ( $dropped === $item['product_id'] ) {
						WC()->cart->remove_cart_item( $key );
					}
				}
			}
		);

		$data = $this->data( $this->preview( $this->two_line_body( 'c20' ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( 2, $data['lines'][1]['quantity'] );
		$this->assertEquals( 638, $data['lines'][1]['before']['total'] );
		$this->assertSame( 0, $data['lines'][1]['in_cart_quantity'] );
		$this->assertEquals( 0, $data['lines'][1]['after']['total'] );
		$this->assertSame(
			array(
				array(
					'product_code' => 'BOOK-B',
					'quantity'     => 2,
				),
			),
			$data['removed_lines']
		);
	}

	public function test_line_not_in_cart_is_reported() {
		$product = $this->create_product( 'BOOK-A' );
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->create_coupon( 'c20' );

		$data = $this->data( $this->preview( $this->body( 'c20' ) ) );

		$this->assertSame( 0, $data['lines'][0]['in_cart_quantity'] );
	}

	public function test_malformed_shipping_method_is_400() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		$response = $this->preview( $this->body( 'c20', array( 'shipping_method' => 'flat_rate' ) ) );

		$this->assertWPError( $response );
		$this->assertSame( 'coupon.shipping_method_invalid', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	public function test_probe_failure_is_500() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );
		$probe = new class() extends Miguel_Coupon_Cart_Probe {
			/**
			 * Always fails.
			 *
			 * @param array $lines       Lines.
			 * @param mixed $coupon_code Code.
			 * @param array $customer    Customer.
			 * @throws Error Always.
			 */
			public static function price( array $lines, $coupon_code, array $customer ) {
				throw new Error( 'boom' );
			}
		};

		$upload_dir = wp_upload_dir();
		$log_file   = trailingslashit( (string) $upload_dir['basedir'] ) . 'miguel-logs/miguel-debug-' . gmdate( 'd-m-Y' ) . '.log';
		clearstatcache();
		$log_offset = file_exists( $log_file ) ? filesize( $log_file ) : 0;
		add_filter( 'miguel_enable_debug_log', '__return_true' );

		$response = $this->preview( $this->body( 'c20' ), $probe );

		$this->assertWPError( $response );
		$this->assertSame( 'coupon.preview_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );

		$logged = null;
		foreach ( explode( PHP_EOL, (string) file_get_contents( $log_file, false, null, $log_offset ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) && 'Coupon preview failed' === $entry['message'] ) {
				$logged = $entry;
			}
		}
		$this->assertIsArray( $logged );
		$this->assertSame( 'boom', $logged['context']['error'] );
		$this->assertSame( 'Error', $logged['context']['class'] );
		$this->assertSame( __FILE__, $logged['context']['file'] );
		$this->assertIsInt( $logged['context']['line'] );
	}

	public function test_nothing_persists() {
		global $wpdb;
		$this->create_product( 'BOOK-A' );
		$coupon        = $this->create_coupon( 'c20' );
		$orders_before = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		$sessions      = "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions";
		$sessions_pre  = (int) $wpdb->get_var( $sessions ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$data = $this->data( $this->preview( $this->body( 'c20' ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame(
			$orders_before,
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
		$this->assertSame( 0, ( new WC_Coupon( $coupon->get_id() ) )->get_usage_count() );
		$this->assertSame( $sessions_pre, (int) $wpdb->get_var( $sessions ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_missing_code_is_a_bad_request() {
		$response = $this->preview(
			array(
				'email'      => 'buyer@example.com',
				'line_items' => array( $this->line( 'BOOK-A' ) ),
			)
		);

		$this->assertWPError( $response );
		$this->assertSame( 'coupon.code_required', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * @dataProvider malformed_line_items
	 *
	 * @param mixed $line_items Malformed line_items value.
	 */
	public function test_malformed_line_items_are_a_bad_request( $line_items ) {
		$response = $this->preview(
			array(
				'code'       => 'c20',
				'email'      => 'buyer@example.com',
				'line_items' => $line_items,
			)
		);

		$this->assertWPError( $response );
		$this->assertSame( 'coupon.line_items_invalid', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * @return array
	 */
	public function malformed_line_items() {
		$valid = $this->line( 'BOOK-A' );

		$no_code = $valid;
		unset( $no_code['product_code'] );

		return array(
			'empty'           => array( array() ),
			'not a list'      => array( 'BOOK-A' ),
			'line not object' => array( array( 'BOOK-A' ) ),
			'no product code' => array( array( $no_code ) ),
			'zero quantity'   => array( array( array_merge( $valid, array( 'quantity' => 0 ) ) ) ),
		);
	}

	public function test_unknown_product_code_answers_the_resolver_error() {
		$this->create_coupon( 'c20' );

		$response = $this->preview( $this->body( 'c20' ) );

		$this->assertWPError( $response );
		$this->assertSame( 'product_code.not_found', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}
}
