<?php
/**
 * Tests for Miguel_Coupon_Cart_Probe.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Coupon_Cart_Probe extends Miguel_Test_Case {

	/**
	 * A simple product.
	 *
	 * @param string $sku   Unique SKU.
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
	 * @param array  $props WC_Coupon props to set.
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
	 * Customer descriptor with overrides.
	 *
	 * @param array $overrides Keys to override.
	 * @return array
	 */
	private function customer( $overrides = array() ) {
		return array_merge(
			array(
				'email'           => 'buyer@example.com',
				'user_id'         => 0,
				'billing'         => null,
				'shipping'        => null,
				'shipping_method' => null,
			),
			$overrides
		);
	}

	/**
	 * Lines for products and quantities.
	 *
	 * @param array $pairs List of array( WC_Product, quantity ).
	 * @return array
	 */
	private function lines( array $pairs ) {
		$lines = array();
		foreach ( $pairs as $pair ) {
			$lines[] = array(
				'product_id' => $pair[0]->get_id(),
				'quantity'   => $pair[1],
			);
		}

		return $lines;
	}

	/**
	 * Two products, one and two pieces.
	 *
	 * @return array array( lines, product 1, product 2 ).
	 */
	private function two_lines() {
		$one = $this->create_product( 'BOOK-A' );
		$two = $this->create_product( 'BOOK-B' );

		return array( $this->lines( array( array( $one, 1 ), array( $two, 2 ) ) ), $one, $two );
	}

	public function test_without_coupon_prices_lines_as_the_shop() {
		list( $lines ) = $this->two_lines();

		$result = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer() );

		$this->assertEqualsWithDelta( 319, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 638, $result['lines'][1]['total'], 0.001 );
		$this->assertEqualsWithDelta( 319, $result['lines'][1]['unit_price'], 0.001 );
		$this->assertSame( 2, $result['lines'][1]['in_cart_quantity'] );
		$this->assertSame( array(), $result['coupons'] );
		$this->assertSame( array(), $result['extra'] );
		$this->assertNull( $result['error_code'] );
		$this->assertNull( $result['message'] );
		$this->assertEqualsWithDelta( 957, $result['totals']['total'], 0.001 );
		$this->assertEqualsWithDelta( 957, $result['totals']['items'], 0.001 );
		$this->assertNull( $result['totals']['shipping'] );
	}

	public function test_percent_coupon() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon( 'c20' );

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'c20', $this->customer() );

		$this->assertEqualsWithDelta( 255.2, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 191.4, $result['totals']['discount'], 0.001 );
		$this->assertSame( array( 'c20' ), $result['coupons'] );
		$this->assertNull( $result['error_code'] );
	}

	public function test_fixed_product_coupon() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon(
			'c50',
			array(
				'discount_type' => 'fixed_product',
				'amount'        => 50,
			)
		);

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'c50', $this->customer() );

		$this->assertEqualsWithDelta( 269, $result['lines'][0]['total'], 0.001 );
	}

	public function test_fixed_cart_coupon_and_item_limit() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon(
			'f100',
			array(
				'discount_type' => 'fixed_cart',
				'amount'        => 100,
			)
		);

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'f100', $this->customer() );

		$this->assertEqualsWithDelta( 857, $result['lines'][0]['total'] + $result['lines'][1]['total'], 0.001 );

		$product = $this->create_product( 'BOOK-C' );
		$this->create_coupon( 'half1', array( 'amount' => 50, 'limit_usage_to_x_items' => 1 ) );

		$result = Miguel_Coupon_Cart_Probe::price( $this->lines( array( array( $product, 2 ) ) ), 'half1', $this->customer() );

		$this->assertEqualsWithDelta( 478.5, $result['lines'][0]['total'] , 0.001 );
	}

	public function test_excluded_product_keeps_its_price() {
		list( $lines, $one, $two ) = $this->two_lines();
		$this->create_coupon( 'ex', array( 'excluded_product_ids' => array( $two->get_id() ) ) );

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'ex', $this->customer() );

		$this->assertEqualsWithDelta( 255.2, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 638, $result['lines'][1]['total'], 0.001 );
	}

	/**
	 * Refused coupons: props to create (null = none), customer overrides, accepted codes.
	 *
	 * @return array
	 */
	public static function refusals() {
		return array(
			'not found'     => array( null, array(), array( WC_Coupon::E_WC_COUPON_NOT_EXIST ) ),
			'expired'       => array( array( 'date_expires' => time() - DAY_IN_SECONDS ), array(), array( WC_Coupon::E_WC_COUPON_EXPIRED ) ),
			'usage limit'   => array( array( 'usage_limit' => 1, 'usage_count' => 1 ), array(), array( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST ) ),
			'other e-mails' => array( array( 'email_restrictions' => array( 'vip@example.com' ) ), array(), array( WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED ) ),
			'min spend'     => array( array( 'minimum_amount' => 1000 ), array(), array( WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET ) ),
			'max spend'     => array( array( 'maximum_amount' => 100 ), array(), array( WC_Coupon::E_WC_COUPON_MAX_SPEND_LIMIT_MET ) ),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param array|null $props    Coupon props, or null when the coupon does not exist.
	 * @param array      $customer Customer overrides.
	 * @param int[]      $codes    Accepted error codes.
	 */
	public function test_refusals_carry_woocommerce_codes( $props, $customer, $codes ) {
		list( $lines ) = $this->two_lines();
		if ( null !== $props ) {
			$this->create_coupon( 'refused', $props );
		}

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'refused', $this->customer( $customer ) );

		$this->assertContains( $result['error_code'], $codes );
		$this->assertSame( array(), $result['coupons'] );
		$this->assertIsString( $result['message'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertSame( wp_strip_all_tags( $result['message'] ), $result['message'] );
		$this->assertEqualsWithDelta( 319, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 957, $result['totals']['total'], 0.001 );
	}

	public function test_per_user_limit_for_registered_customer_and_guest() {
		list( $lines ) = $this->two_lines();
		$user_id = self::factory()->user->create( array( 'user_email' => 'regular@example.com' ) );
		$coupon  = $this->create_coupon( 'once', array( 'usage_limit_per_user' => 1 ) );
		$coupon->increase_usage_count( $user_id );
		$coupon->increase_usage_count( 'guest@example.com' );
		$codes = array( WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED, WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST );

		$registered = Miguel_Coupon_Cart_Probe::price( $lines, 'once', $this->customer( array( 'email' => 'regular@example.com', 'user_id' => $user_id ) ) );
		$guest      = Miguel_Coupon_Cart_Probe::price( $lines, 'once', $this->customer( array( 'email' => 'Guest@Example.com' ) ) );
		$other      = Miguel_Coupon_Cart_Probe::price( $lines, 'once', $this->customer( array( 'email' => 'other@example.com' ) ) );

		$this->assertContains( $registered['error_code'], $codes );
		$this->assertSame( array(), $registered['coupons'] );
		$this->assertContains( $guest['error_code'], $codes );
		$this->assertSame( array(), $guest['coupons'] );
		$this->assertNull( $other['error_code'] );
		$this->assertSame( array( 'once' ), $other['coupons'] );
	}

	/**
	 * Apply a coupon of the shop's own whenever a product is added, as an auto-apply plugin does.
	 *
	 * @param string $code Coupon code.
	 */
	private function auto_apply( $code ) {
		add_action(
			'woocommerce_add_to_cart',
			function () use ( $code ) {
				if ( ! WC()->cart->has_discount( $code ) ) {
					WC()->cart->apply_coupon( $code );
				}
			}
		);
	}

	public function test_another_coupons_customer_check_does_not_refuse_the_requested_coupon() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon( 'c20' );
		$auto = $this->create_coupon(
			'auto',
			array(
				'discount_type'        => 'fixed_cart',
				'amount'               => 5,
				'usage_limit_per_user' => 1,
			)
		);
		$auto->increase_usage_count( 'buyer@example.com' );
		$this->auto_apply( 'auto' );

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'c20', $this->customer() );

		$this->assertNull( $result['error_code'] );
		$this->assertNull( $result['message'] );
		$this->assertContains( 'c20', $result['coupons'] );
	}

	public function test_refusal_beside_an_individual_use_coupon_carries_its_code() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon( 'c20' );
		$this->create_coupon( 'solo', array( 'individual_use' => true ) );
		$this->auto_apply( 'solo' );

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'c20', $this->customer() );

		$this->assertSame( WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY, $result['error_code'] );
		$this->assertIsString( $result['message'] );
		$this->assertSame( array( 'solo' ), $result['coupons'] );
	}

	public function test_line_the_shop_will_not_add() {
		$sold_out = $this->create_product( 'OUT' );
		$sold_out->set_manage_stock( false );
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();
		$single = $this->create_product( 'SINGLE' );
		$single->set_sold_individually( true );
		$single->save();

		$result = Miguel_Coupon_Cart_Probe::price( $this->lines( array( array( $sold_out, 1 ), array( $single, 3 ) ) ), null, $this->customer() );

		$this->assertSame( 0, $result['lines'][0]['in_cart_quantity'] );
		$this->assertEqualsWithDelta( 0, $result['lines'][0]['total'], 0.001 );
		$this->assertNotEmpty( $result['notices'] );
		$this->assertSame( 1, $result['lines'][1]['in_cart_quantity'] );
		$this->assertEqualsWithDelta( 319, $result['lines'][1]['total'], 0.001 );
	}

	public function test_duplicate_product_lines_share_the_item() {
		$product = $this->create_product( 'BOOK-A' );

		$result = Miguel_Coupon_Cart_Probe::price( $this->lines( array( array( $product, 1 ), array( $product, 2 ) ) ), null, $this->customer() );

		$this->assertEqualsWithDelta( 319, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 638, $result['lines'][1]['total'], 0.001 );
		$this->assertEqualsWithDelta( 319, $result['lines'][0]['unit_price'], 0.001 );
		$this->assertEqualsWithDelta( 319, $result['lines'][1]['unit_price'], 0.001 );
		$this->assertSame( array(), $result['extra'] );
	}

	public function test_tax_is_included() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_default_country', 'CZ' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'CZ',
				'tax_rate'         => '21.0000',
				'tax_rate_name'    => 'VAT',
				'tax_rate_class'   => '',
			)
		);
		$product = $this->create_product( 'BOOK-A' );

		$result = Miguel_Coupon_Cart_Probe::price( $this->lines( array( array( $product, 1 ) ) ), null, $this->customer( array( 'shipping' => array( 'country' => 'CZ' ), 'billing' => array( 'country' => 'CZ' ) ) ) );

		$this->assertEqualsWithDelta( 319, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 55.36, $result['lines'][0]['tax'], 0.001 );
		$this->assertEqualsWithDelta( 55.36, $result['totals']['tax'], 0.001 );
	}

	/**
	 * Czech VAT 21 % in prices, the shop based in Czechia, tax by the shipping address.
	 */
	private function czech_vat_included() {
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
	}

	public function test_tax_without_an_address_uses_the_default_customer_location() {
		$this->czech_vat_included();
		$product = $this->create_product( 'BOOK-A' );

		$result = Miguel_Coupon_Cart_Probe::price( $this->lines( array( array( $product, 1 ) ) ), null, $this->customer() );

		$this->assertEqualsWithDelta( 319, $result['lines'][0]['total'], 0.001 );
		$this->assertEqualsWithDelta( 55.36, $result['lines'][0]['tax'], 0.001 );
		$this->assertEqualsWithDelta( 319, $result['totals']['total'], 0.001 );
		$this->assertEqualsWithDelta( 55.36, $result['totals']['tax'], 0.001 );
	}

	public function test_shipping_only_with_a_method() {
		list( $lines ) = $this->two_lines();
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Czechia' );
		$zone->add_location( 'CZ', 'country' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'cost' => '89', 'tax_status' => 'none' ) );
		$address = array( 'country' => 'CZ', 'state' => '', 'city' => 'Praha', 'postcode' => '11000' );

		$without = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer( array( 'shipping' => $address ) ) );
		$with    = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer( array( 'shipping' => $address, 'shipping_method' => 'flat_rate:' . $instance_id ) ) );

		$this->assertNull( $without['totals']['shipping'] );
		$this->assertEqualsWithDelta( 89, $with['totals']['shipping'], 0.001 );
		$this->assertEqualsWithDelta( 957 + 89, $with['totals']['total'], 0.001 );
	}

	public function test_shipping_needs_an_address_besides_the_method() {
		list( $lines ) = $this->two_lines();
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Czechia' );
		$zone->add_location( 'CZ', 'country' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', array( 'cost' => '89', 'tax_status' => 'none' ) );
		$method = 'flat_rate:' . $instance_id;
		// The default customer location is in the zone, so the cart could price shipping without an address.
		update_option( 'woocommerce_default_country', 'CZ' );
		update_option( 'woocommerce_default_customer_address', 'base' );

		$no_address = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer( array( 'shipping_method' => $method ) ) );
		$no_method  = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer( array( 'shipping' => array( 'country' => 'CZ' ) ) ) );
		$billing    = Miguel_Coupon_Cart_Probe::price( $lines, null, $this->customer( array( 'shipping_method' => $method, 'billing' => array( 'country' => 'CZ', 'postcode' => '11000' ) ) ) );

		$this->assertNull( $no_address['totals']['shipping'] );
		$this->assertEqualsWithDelta( 957, $no_address['totals']['total'], 0.001 );
		$this->assertNull( $no_method['totals']['shipping'] );
		$this->assertEqualsWithDelta( 957, $no_method['totals']['total'], 0.001 );
		$this->assertEqualsWithDelta( 89, $billing['totals']['shipping'], 0.001 );
		$this->assertEqualsWithDelta( 957 + 89, $billing['totals']['total'], 0.001 );
	}

	public function test_coupon_usage_is_not_counted() {
		list( $lines ) = $this->two_lines();
		$this->create_coupon( 'c20' );

		$result = Miguel_Coupon_Cart_Probe::price( $lines, 'c20', $this->customer() );

		$this->assertSame( array( 'c20' ), $result['coupons'] );
		$this->assertSame( 0, ( new WC_Coupon( 'c20' ) )->get_usage_count() );
		$this->assertSame( array(), wc_get_orders( array( 'limit' => -1 ) ) );
	}
}
