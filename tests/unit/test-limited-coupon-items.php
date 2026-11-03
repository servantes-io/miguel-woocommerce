<?php
/**
 * Miguel_Limited_Coupon_Items on the shop's own cart.
 *
 * @package Miguel\Tests
 */

require_once dirname( __DIR__ ) . '/helpers/class-miguel-helper-coupon-plugins.php';

/**
 * Which item a coupon limited to X items goes to, and the cart order the customer sees.
 */
class Test_Miguel_Limited_Coupon_Items extends Miguel_Test_Case {

	public function setUp(): void {
		parent::setUp();

		// Other tests leave items in the shared cart.
		WC()->cart->empty_cart();
	}

	public function tearDown(): void {
		Miguel_Mock_Melvil_Pricing::uninstall();
		WC()->cart->empty_cart();

		parent::tearDown();
	}

	/**
	 * A simple product at a regular price.
	 *
	 * @param string $price Regular price.
	 * @return WC_Product
	 */
	private function product( $price ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $price );
		$product->save();

		return $product;
	}

	public function test_coupon_goes_to_the_cheapest_item_and_the_cart_keeps_its_order() {
		Miguel_Mock_Melvil_Pricing::install();
		$coupon = new WC_Coupon();
		$coupon->set_props(
			array(
				'code'                   => 'test100',
				'discount_type'          => 'percent',
				'amount'                 => 100,
				'limit_usage_to_x_items' => 1,
			)
		);
		$coupon->save();
		$cart = WC()->cart;
		$keys = array(
			$cart->add_to_cart( $this->product( '382' )->get_id() ),
			$cart->add_to_cart( $this->product( '365' )->get_id() ),
			$cart->add_to_cart( $this->product( '339' )->get_id() ),
		);

		$cart->apply_coupon( 'test100' );
		$cart->calculate_totals();

		$this->assertSame( $keys, array_keys( $cart->get_cart() ) );
		$this->assertEquals( array( 325, 310, 0 ), array_map( fn( $item ) => $item['line_total'], array_values( $cart->get_cart() ) ) );
	}

	public function test_without_a_limited_coupon_the_cart_is_left_alone() {
		Miguel_Mock_Melvil_Pricing::install();
		$cart = WC()->cart;
		$cart->add_to_cart( $this->product( '382' )->get_id() );
		$cart->add_to_cart( $this->product( '339' )->get_id() );

		$cart->calculate_totals();

		$this->assertEquals( array( 325, 288 ), array_map( fn( $item ) => $item['line_total'], array_values( $cart->get_cart() ) ) );
	}
}
