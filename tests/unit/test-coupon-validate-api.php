<?php
/**
 * Tests for POST /miguel/v1/coupons/validate.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Coupon_Validate_Api extends Miguel_Test_Case {

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
	 * @param string $price        Unit price as Miguel prices it.
	 * @param int    $quantity     Quantity.
	 * @return array
	 */
	private function line( $product_code, $price = '271', $quantity = 1 ) {
		return array(
			'product_code' => $product_code,
			'quantity'     => $quantity,
			'price'        => $price,
		);
	}

	/**
	 * POST a body to the route's callback.
	 *
	 * @param array $body Request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function validate( array $body ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/coupons/validate' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return ( new Miguel_Coupon_Validate_Api( new Miguel_Hook_Manager() ) )->validate_coupon( $request );
	}

	/**
	 * A request for one line of BOOK-A.
	 *
	 * @param string $code  Coupon code.
	 * @param string $email Customer e-mail.
	 * @return array
	 */
	private function body( $code, $email = 'buyer@example.com' ) {
		return array(
			'code'       => $code,
			'email'      => $email,
			'line_items' => array( $this->line( 'BOOK-A' ) ),
		);
	}

	/**
	 * Assert a refusal carrying WooCommerce's coupon error code.
	 *
	 * @param int|int[] $expected_codes WC_Coupon::E_WC_COUPON_* constant, or the ones accepted.
	 * @param mixed     $response       Route response.
	 */
	private function assert_refused( $expected_codes, $response ) {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 422, $response->get_status() );

		$data = $response->get_data();
		$this->assertContains( $data['error_code'], (array) $expected_codes );
		$this->assertIsString( $data['message'] );
		$this->assertNotSame( '', $data['message'] );
		$this->assertSame( wp_strip_all_tags( $data['message'] ), $data['message'] );
	}

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with(
				'rest_api_init',
				$this->isType( 'array' )
			);

		$api = new Miguel_Coupon_Validate_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_route_is_registered_with_the_shared_auth() {
		$api = new Miguel_Coupon_Validate_Api( new Miguel_Hook_Manager() );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/miguel/v1/coupons/validate', $routes );

		$route = $routes['/miguel/v1/coupons/validate'][0];
		$this->assertArrayHasKey( 'POST', $route['methods'] );
		$this->assertSame( array( $api, 'validate_api_access' ), $route['permission_callback'] );
	}

	public function test_route_refuses_a_request_without_the_api_key() {
		$api = new Miguel_Coupon_Validate_Api( new Miguel_Hook_Manager() );
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/miguel/v1/coupons/validate' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_valid_coupon_answers_its_terms_and_eligible_codes() {
		$this->create_product( 'BOOK-A' );
		$this->create_product( 'BOOK-B' );
		$this->create_coupon(
			'MELVIL20',
			array(
				'limit_usage_to_x_items' => 3,
			)
		);

		$response = $this->validate(
			array(
				'code'       => 'MELVIL20',
				'email'      => 'buyer@example.com',
				'line_items' => array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B', '200', 2 ) ),
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'code'                   => wc_format_coupon_code( 'MELVIL20' ),
				'discount_type'          => 'percent',
				'amount'                 => 20.0,
				'limit_usage_to_x_items' => 3,
				'eligible_product_codes' => array( 'BOOK-A', 'BOOK-B' ),
			),
			$response->get_data()
		);
	}

	public function test_no_item_limit_is_null() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'NOLIMIT' );

		$response = $this->validate( $this->body( 'NOLIMIT' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['limit_usage_to_x_items'] );
	}

	public function test_validation_creates_no_order() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'MELVIL20' );
		$orders_before = wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		$this->validate( $this->body( 'MELVIL20' ) );

		$this->assertSame(
			$orders_before,
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
	}

	public function test_unknown_coupon_is_refused() {
		$this->create_product( 'BOOK-A' );

		$this->assert_refused( WC_Coupon::E_WC_COUPON_NOT_EXIST, $this->validate( $this->body( 'NOSUCHCODE' ) ) );
	}

	public function test_expired_coupon_is_refused() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'OLD', array( 'date_expires' => time() - DAY_IN_SECONDS ) );

		$this->assert_refused( WC_Coupon::E_WC_COUPON_EXPIRED, $this->validate( $this->body( 'OLD' ) ) );
	}

	public function test_used_up_coupon_is_refused() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon(
			'ONCE',
			array(
				'usage_limit' => 1,
				'usage_count' => 1,
			)
		);

		// WooCommerce answers 116 (..._COUPON_STUCK_GUEST) rather than 106 when it reads the held
		// count as the string "0" from the database, which it does for this route's guest request.
		$usage_limit = array( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST );

		$this->assert_refused( $usage_limit, $this->validate( $this->body( 'ONCE' ) ) );
	}

	public function test_coupon_used_up_by_this_customer_is_refused() {
		$this->create_product( 'BOOK-A' );
		$user_id = self::factory()->user->create( array( 'user_email' => 'regular@example.com' ) );
		$coupon  = $this->create_coupon( 'ONCEEACH', array( 'usage_limit_per_user' => 1 ) );
		$coupon->increase_usage_count( $user_id );

		$this->assert_refused( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, $this->validate( $this->body( 'ONCEEACH', 'regular@example.com' ) ) );
		$this->assertSame( 200, $this->validate( $this->body( 'ONCEEACH', 'someone.else@example.com' ) )->get_status() );
	}

	public function test_coupon_used_up_by_this_guest_email_is_refused() {
		$this->create_product( 'BOOK-A' );
		$coupon = $this->create_coupon( 'ONCEEACH', array( 'usage_limit_per_user' => 1 ) );
		$coupon->increase_usage_count( 'guest@example.com' );
		$usage_limit = array( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST );

		$this->assert_refused( $usage_limit, $this->validate( $this->body( 'ONCEEACH', 'Guest@Example.com' ) ) );
		$this->assertSame( 200, $this->validate( $this->body( 'ONCEEACH', 'other.guest@example.com' ) )->get_status() );
	}

	public function test_coupon_for_other_emails_is_refused() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'VIP', array( 'email_restrictions' => array( 'vip@example.com' ) ) );

		$this->assert_refused( WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED, $this->validate( $this->body( 'VIP', 'buyer@example.com' ) ) );
		$this->assertSame( 200, $this->validate( $this->body( 'VIP', 'vip@example.com' ) )->get_status() );
	}

	public function test_coupon_for_an_email_domain_accepts_that_domain_only() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'STAFF', array( 'email_restrictions' => array( '*@servantes.cz' ) ) );

		$this->assertSame( 200, $this->validate( $this->body( 'STAFF', 'jan@servantes.cz' ) )->get_status() );
		$this->assert_refused( WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED, $this->validate( $this->body( 'STAFF', 'jan@servantesXcz.com' ) ) );
	}

	public function test_minimum_spend_is_checked_against_the_sent_prices() {
		$this->create_product( 'BOOK-A', '1000' );
		$this->create_coupon( 'MIN500', array( 'minimum_amount' => 500 ) );

		$this->assert_refused(
			WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET,
			$this->validate(
				array(
					'code'       => 'MIN500',
					'email'      => 'buyer@example.com',
					'line_items' => array( $this->line( 'BOOK-A', '271' ) ),
				)
			)
		);

		$this->assertSame(
			200,
			$this->validate(
				array(
					'code'       => 'MIN500',
					'email'      => 'buyer@example.com',
					'line_items' => array( $this->line( 'BOOK-A', '271', 2 ) ),
				)
			)->get_status()
		);
	}

	public function test_maximum_spend_is_refused() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'MAX100', array( 'maximum_amount' => 100 ) );

		$this->assert_refused( WC_Coupon::E_WC_COUPON_MAX_SPEND_LIMIT_MET, $this->validate( $this->body( 'MAX100' ) ) );
	}

	public function test_eligible_codes_follow_product_include_and_exclude_lists() {
		$included = $this->create_product( 'BOOK-A' );
		$this->create_product( 'BOOK-B' );
		$excluded = $this->create_product( 'BOOK-C' );
		$this->create_coupon( 'ONLYA', array( 'product_ids' => array( $included->get_id() ) ) );
		$this->create_coupon( 'NOTC', array( 'excluded_product_ids' => array( $excluded->get_id() ) ) );
		$lines = array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B' ), $this->line( 'BOOK-C' ) );

		$only_a = $this->validate(
			array(
				'code'       => 'ONLYA',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);
		$not_c  = $this->validate(
			array(
				'code'       => 'NOTC',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);

		$this->assertSame( array( 'BOOK-A' ), $only_a->get_data()['eligible_product_codes'] );
		$this->assertSame( array( 'BOOK-A', 'BOOK-B' ), $not_c->get_data()['eligible_product_codes'] );
	}

	public function test_eligible_codes_follow_category_include_and_exclude_lists() {
		$fiction = wp_insert_term( 'Fiction', 'product_cat' )['term_id'];
		$poetry  = wp_insert_term( 'Poetry', 'product_cat' )['term_id'];
		$novel   = $this->create_product( 'BOOK-A' );
		$poems   = $this->create_product( 'BOOK-B' );
		$novel->set_category_ids( array( $fiction ) );
		$novel->save();
		$poems->set_category_ids( array( $poetry ) );
		$poems->save();
		$this->create_coupon( 'FICTION', array( 'product_categories' => array( $fiction ) ) );
		$this->create_coupon( 'NOPOETRY', array( 'excluded_product_categories' => array( $poetry ) ) );
		$lines = array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B' ) );

		$fiction_only = $this->validate(
			array(
				'code'       => 'FICTION',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);
		$no_poetry    = $this->validate(
			array(
				'code'       => 'NOPOETRY',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);

		$this->assertSame( array( 'BOOK-A' ), $fiction_only->get_data()['eligible_product_codes'] );
		$this->assertSame( array( 'BOOK-A' ), $no_poetry->get_data()['eligible_product_codes'] );
	}

	public function test_eligible_codes_leave_out_sale_items_when_the_coupon_excludes_them() {
		$this->create_product( 'BOOK-A' );
		$on_sale = $this->create_product( 'BOOK-B' );
		$on_sale->set_sale_price( '250' );
		$on_sale->save();
		$this->create_coupon( 'NOSALE', array( 'exclude_sale_items' => true ) );

		$response = $this->validate(
			array(
				'code'       => 'NOSALE',
				'email'      => 'buyer@example.com',
				'line_items' => array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B' ) ),
			)
		);

		$this->assertSame( array( 'BOOK-A' ), $response->get_data()['eligible_product_codes'] );
	}

	public function test_fixed_cart_coupon_lists_the_lines_its_rules_allow() {
		$this->create_product( 'BOOK-A' );
		$included = $this->create_product( 'BOOK-B' );
		$this->create_coupon( 'CART50', array( 'discount_type' => 'fixed_cart' ) );
		$this->create_coupon(
			'CARTB',
			array(
				'discount_type' => 'fixed_cart',
				'product_ids'   => array( $included->get_id() ),
			)
		);
		$lines = array( $this->line( 'BOOK-A' ), $this->line( 'BOOK-B' ) );

		$any = $this->validate(
			array(
				'code'       => 'CART50',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);
		$b   = $this->validate(
			array(
				'code'       => 'CARTB',
				'email'      => 'buyer@example.com',
				'line_items' => $lines,
			)
		);

		$this->assertSame( 'fixed_cart', $any->get_data()['discount_type'] );
		$this->assertSame( array( 'BOOK-A', 'BOOK-B' ), $any->get_data()['eligible_product_codes'] );
		$this->assertSame( array( 'BOOK-B' ), $b->get_data()['eligible_product_codes'] );
	}

	public function test_missing_code_is_a_bad_request() {
		$response = $this->validate(
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
		$response = $this->validate(
			array(
				'code'       => 'MELVIL20',
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

		$no_price = $valid;
		unset( $no_price['price'] );

		return array(
			'empty'            => array( array() ),
			'not a list'       => array( 'BOOK-A' ),
			'line not object'  => array( array( 'BOOK-A' ) ),
			'no product code'  => array( array( $no_code ) ),
			'zero quantity'    => array( array( array_merge( $valid, array( 'quantity' => 0 ) ) ) ),
			'no price'         => array( array( $no_price ) ),
			'negative price'   => array( array( array_merge( $valid, array( 'price' => '-1' ) ) ) ),
			'non-number price' => array( array( array_merge( $valid, array( 'price' => 'free' ) ) ) ),
		);
	}

	public function test_unknown_product_code_answers_the_resolver_error() {
		$this->create_coupon( 'MELVIL20' );

		$response = $this->validate( $this->body( 'MELVIL20' ) );

		$this->assertWPError( $response );
		$this->assertSame( 'product_code.not_found', $response->get_error_code() );
	}
}
