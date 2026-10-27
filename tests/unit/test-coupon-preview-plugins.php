<?php
/**
 * POST /miguel/v1/coupons/preview with Smart Coupons and Melvil's pricing plugin, pinned with
 * stand-ins that hook into WooCommerce the way the real plugins do.
 *
 * @package Miguel\Tests
 */

// The real plugin's class is loaded only in a local run against the licensed zips.
define( 'MIGUEL_TEST_REAL_SMART_COUPONS', class_exists( 'WC_SC_Coupons_By_Location', false ) );
require_once dirname( __DIR__ ) . '/helpers/class-miguel-helper-coupon-plugins.php';

/**
 * Smart Coupons and Melvil cases of the coupon preview.
 */
class Test_Miguel_Coupon_Preview_Plugins extends Miguel_Test_Case {

	/**
	 * The stand-ins, uninstalled after every test.
	 *
	 * @var string[]
	 */
	private const MOCKS = array(
		'Miguel_Mock_Sc_Gift',
		'WC_SC_Coupons_By_Location',
		'Miguel_Mock_Sc_Payment_Method',
		'Miguel_Mock_Sc_Revalidate',
		'Miguel_Mock_Sc_Store_Credit',
		'Miguel_Mock_Sc_Auto_Apply',
		'Miguel_Mock_Melvil_Pricing',
	);

	public function setUp(): void {
		parent::setUp();

		if ( MIGUEL_TEST_REAL_SMART_COUPONS ) {
			$this->markTestSkipped( 'Smart Coupons itself is loaded; these tests pin its stand-ins.' );
		}
	}

	public function tearDown(): void {
		if ( ! MIGUEL_TEST_REAL_SMART_COUPONS ) {
			foreach ( self::MOCKS as $mock ) {
				$mock::uninstall();
			}
		}

		parent::tearDown();
	}

	/**
	 * A product whose Miguel code is its bare SKU.
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
	 * @param array  $props WC_Coupon props.
	 * @param array  $meta  Coupon meta.
	 * @return WC_Coupon
	 */
	private function create_coupon( $code, $props = array(), $meta = array() ) {
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
		foreach ( $meta as $key => $value ) {
			$coupon->update_meta_data( $key, $value );
		}
		$coupon->save();

		return $coupon;
	}

	/**
	 * A coupon that applies only to billing addresses in the Czech Republic.
	 *
	 * @param string $code Coupon code.
	 * @return WC_Coupon
	 */
	private function create_cz_billing_coupon( $code ) {
		return $this->create_coupon(
			$code,
			array(),
			array(
				'sa_cbl_locations_lookup_in' => array( 'address' => 'billing' ),
				'sa_cbl_billing_locations'   => array( 'additional_locations' => array( 'CZ' ) ),
			)
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
				'line_items' => array(
					array(
						'product_code' => 'BOOK-A',
						'quantity'     => 1,
					),
				),
			),
			$more
		);
	}

	/**
	 * The data of the route's 200 response.
	 *
	 * @param array $body Request body.
	 * @return array
	 */
	private function preview( array $body ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/coupons/preview' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		$response = ( new Miguel_Coupon_Preview_Api( new Miguel_Hook_Manager() ) )->preview_coupon( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	public function test_gift_product_is_an_added_line() {
		Miguel_Mock_Sc_Gift::install();
		$this->create_product( 'BOOK-A' );
		$gift = $this->create_product( 'BOOK-G', '99' );
		$this->create_coupon(
			'gift',
			array(),
			array(
				'wc_sc_add_product_details' => array(
					array(
						'product_id'      => $gift->get_id(),
						'quantity'        => 1,
						'discount_amount' => 100,
						'discount_type'   => 'percent',
					),
				),
			)
		);

		$data = $this->preview( $this->body( 'gift' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertCount( 1, $data['added_lines'] );
		$this->assertSame( 'BOOK-G', $data['added_lines'][0]['product_code'] );
		$this->assertSame( $gift->get_id(), $data['added_lines'][0]['product_id'] );
		$this->assertSame( 1, $data['added_lines'][0]['quantity'] );
		$this->assertSame( 'smart_coupons_action', $data['added_lines'][0]['source'] );
		$this->assertContains( 'Gift added', $data['notices'] );
		$this->assertNull( $data['gift_choices'] );
	}

	public function test_gift_whose_product_code_is_ambiguous_has_no_code() {
		Miguel_Mock_Sc_Gift::install();
		$this->create_product( 'BOOK-A' );
		$gift = $this->create_product( 'BOOK-G', '99' );
		$gift->update_meta_data( '_miguel_code', 'SHARED' );
		$gift->save();
		$other = $this->create_product( 'BOOK-O', '99' );
		$other->update_meta_data( '_miguel_code', 'SHARED' );
		$other->save();
		$this->create_coupon(
			'gift',
			array(),
			array(
				'wc_sc_add_product_details' => array(
					array(
						'product_id' => $gift->get_id(),
						'quantity'   => 1,
					),
				),
			)
		);

		$data = $this->preview( $this->body( 'gift' ) );

		$this->assertCount( 1, $data['added_lines'] );
		$this->assertSame( $gift->get_id(), $data['added_lines'][0]['product_id'] );
		$this->assertNull( $data['added_lines'][0]['product_code'] );
		$this->assertSame( 'BOOK-G', $data['added_lines'][0]['sku'] );
	}

	public function test_selectable_gift_lists_choices() {
		Miguel_Mock_Sc_Gift::install();
		$this->create_product( 'BOOK-A' );
		$first  = $this->create_product( 'BOOK-G1', '99' );
		$second = $this->create_product( 'BOOK-G2', '149' );
		$this->create_coupon(
			'pick',
			array(),
			array(
				'wc_sc_no_of_selectable_product' => 'yes',
				'wc_sc_add_product_details'      => array(
					array(
						'product_id'      => $first->get_id(),
						'quantity'        => 1,
						'discount_amount' => 100,
						'discount_type'   => 'percent',
					),
					array(
						'product_id'      => $second->get_id(),
						'quantity'        => 2,
						'discount_amount' => 50,
						'discount_type'   => 'flat',
					),
				),
			)
		);

		$data = $this->preview( $this->body( 'pick' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array(), $data['added_lines'] );
		$this->assertSame(
			array(
				array(
					'product_code'    => 'BOOK-G1',
					'product_id'      => $first->get_id(),
					'sku'             => 'BOOK-G1',
					'name'            => $first->get_name(),
					'quantity'        => 1,
					'discount_type'   => 'percent',
					'discount_amount' => 100.0,
				),
				array(
					'product_code'    => 'BOOK-G2',
					'product_id'      => $second->get_id(),
					'sku'             => 'BOOK-G2',
					'name'            => $second->get_name(),
					'quantity'        => 2,
					'discount_type'   => 'flat',
					'discount_amount' => 50.0,
				),
			),
			$data['gift_choices']['products']
		);
	}

	public function test_location_rule_without_address_is_unchecked_not_refused() {
		WC_SC_Coupons_By_Location::install();
		$this->create_product( 'BOOK-A' );
		$this->create_cz_billing_coupon( 'cz' );

		$data = $this->preview( $this->body( 'cz' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array( 'location' ), $data['unchecked'] );

		$data = $this->preview( $this->body( 'cz', array( 'billing' => array( 'country' => 'SK' ) ) ) );

		$this->assertFalse( $data['applied'] );
		$this->assertSame( 0, $data['error_code'] );
		$this->assertSame( 'Coupon is not valid for the billing address', $data['message'] );
		$this->assertSame( array(), $data['unchecked'] );

		$data = $this->preview( $this->body( 'cz', array( 'billing' => array( 'country' => 'CZ' ) ) ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array(), $data['unchecked'] );
	}

	public function test_location_filter_is_restored() {
		WC_SC_Coupons_By_Location::install();
		$this->create_product( 'BOOK-A' );
		$this->create_cz_billing_coupon( 'cz' );

		$data = $this->preview( $this->body( 'cz' ) );

		$this->assertSame( array( 'location' ), $data['unchecked'] );
		$this->assertSame( 11, has_filter( 'woocommerce_coupon_is_valid', array( WC_SC_Coupons_By_Location::get_instance(), 'validate' ) ) );
	}

	public function test_location_filter_is_restored_when_pricing_fails() {
		WC_SC_Coupons_By_Location::install();
		$this->create_product( 'BOOK-A' );
		$this->create_cz_billing_coupon( 'cz' );
		$fail = function () {
			throw new Error( 'boom' );
		};
		add_action( 'woocommerce_before_calculate_totals', $fail );

		$request = new WP_REST_Request( 'POST', '/miguel/v1/coupons/preview' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $this->body( 'cz' ) ) );
		$response = ( new Miguel_Coupon_Preview_Api( new Miguel_Hook_Manager() ) )->preview_coupon( $request );
		remove_action( 'woocommerce_before_calculate_totals', $fail );

		$this->assertWPError( $response );
		$this->assertSame( 11, has_filter( 'woocommerce_coupon_is_valid', array( WC_SC_Coupons_By_Location::get_instance(), 'validate' ) ) );
	}

	public function test_shipping_method_rule_without_method_is_unchecked() {
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'ship', array(), array( 'wc_sc_shipping_method_ids' => array( 'flat_rate' ) ) );

		$data = $this->preview( $this->body( 'ship' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array( 'shipping_method' ), $data['unchecked'] );
	}

	public function test_payment_method_rule_passes_for_miguel() {
		Miguel_Mock_Sc_Payment_Method::install();
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'miguelonly', array(), array( 'wc_sc_payment_method_ids' => array( 'miguel' ) ) );
		$this->create_coupon( 'bankonly', array(), array( 'wc_sc_payment_method_ids' => array( 'bacs' ) ) );

		$data = $this->preview( $this->body( 'miguelonly' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array(), $data['unchecked'] );

		$data = $this->preview( $this->body( 'bankonly' ) );

		$this->assertFalse( $data['applied'] );
		$this->assertSame( 0, $data['error_code'] );
		$this->assertSame( Miguel_Mock_Sc_Payment_Method::MESSAGE, $data['message'] );
	}

	public function test_coupon_removed_during_totals_is_not_applied() {
		Miguel_Mock_Sc_Revalidate::install();
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'fragile', array(), array( 'mock_remove_on_totals' => 'yes' ) );

		$data = $this->preview( $this->body( 'fragile' ) );

		$this->assertFalse( $data['applied'] );
		$this->assertSame( 0, $data['error_code'] );
		$this->assertSame( 'Coupon removed by rule', $data['message'] );
		$this->assertSame( $data['lines'][0]['before'], $data['lines'][0]['after'] );
		$this->assertSame( $data['totals']['before'], $data['totals']['after'] );
	}

	public function test_store_credit_lands_in_totals() {
		Miguel_Mock_Sc_Store_Credit::install();
		$this->create_product( 'BOOK-A' );
		$this->create_coupon(
			'credit',
			array(
				'discount_type' => 'smart_coupon',
				'amount'        => 100,
			)
		);

		$first  = $this->preview( $this->body( 'credit' ) );
		$second = $this->preview( $this->body( 'credit' ) );

		foreach ( array( $first, $second ) as $data ) {
			$this->assertTrue( $data['applied'] );
			$this->assertEquals( 319, $data['totals']['before']['total'] );
			$this->assertEquals( 219, $data['totals']['after']['total'] );
			$this->assertEquals( 100, $data['totals']['after']['discount'] );
			$this->assertEquals( 319, $data['lines'][0]['after']['total'] );
			$this->assertFalse( $data['lines'][0]['discounted'] );
		}
		$this->assertSame( $first, $second );
	}

	public function test_auto_applied_coupon_is_reported() {
		Miguel_Mock_Sc_Auto_Apply::install();
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );
		$this->create_coupon(
			'auto5',
			array(
				'discount_type' => 'fixed_cart',
				'amount'        => 5,
			)
		);

		$data = $this->preview( $this->body( 'c20' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertSame( array( 'auto5' ), $data['other_coupons'] );
		$this->assertEquals( 314, $data['totals']['before']['total'] );
		$this->assertEquals( 5, $data['totals']['before']['discount'] );
		$this->assertEquals( 250.2, $data['totals']['after']['total'] );
		$this->assertEquals( 68.8, $data['totals']['after']['discount'] );
	}

	public function test_melvil_style_discount_in_line_price() {
		Miguel_Mock_Melvil_Pricing::install();
		$this->create_product( 'BOOK-A' );
		$this->create_coupon( 'c20' );

		$data = $this->preview( $this->body( 'c20' ) );

		$this->assertTrue( $data['applied'] );
		$this->assertEquals( 319, $data['lines'][0]['before']['total'] );
		$this->assertEquals( 255.2, $data['lines'][0]['after']['total'] );
		$this->assertTrue( $data['lines'][0]['discounted'] );
		$this->assertEquals( 0, $data['totals']['after']['discount'] );
		$this->assertEquals( 255.2, $data['totals']['after']['total'] );
	}
}
