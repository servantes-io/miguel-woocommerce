<?php
/**
 * Tests for Miguel_Order_Refunds.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Order_Refunds extends Miguel_Test_Case {

	/**
	 * Rows: line total, ordered quantity, refunded quantity, refunded amount, expected entitled units.
	 *
	 * @return array
	 */
	public function entitlement_cases(): array {
		return array(
			'quantity refunded, no money'           => array( 199.00, 1, 1, 0.00, 0 ),
			'full amount refunded, quantity empty'  => array( 199.00, 1, 0, 199.00, 0 ),
			'compensation keeps access'             => array( 199.00, 1, 0, 40.00, 1 ),
			'one of two units by quantity'          => array( 398.00, 2, 1, 0.00, 1 ),
			'one of two units by amount'            => array( 398.00, 2, 0, 199.00, 1 ),
			'amount over one unit but short of two' => array( 398.00, 2, 0, 250.00, 1 ),
			'amount wins over a smaller quantity'   => array( 398.00, 2, 1, 398.00, 0 ),
			'free line refunded by quantity'        => array( 0.00, 1, 1, 0.00, 0 ),
			'free line, no refund'                  => array( 0.00, 1, 0, 0.00, 1 ),
			'no refund'                             => array( 199.00, 1, 0, 0.00, 1 ),
			'rounding: 33.33 of a 100.00/3 line'    => array( 100.00, 3, 0, 33.33, 2 ),
		);
	}

	/**
	 * @dataProvider entitlement_cases
	 */
	public function test_entitled_quantity( $line_total, $ordered, $refund_qty, $refund_amount, $expected ): void {
		list( $order, $item_id ) = $this->order_with_line( $line_total, $ordered );

		if ( $refund_qty > 0 || $refund_amount > 0 ) {
			Miguel_Helper_Order::refund_line( $order, $item_id, $refund_qty, $refund_amount );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $expected, Miguel_Order_Refunds::get_entitled_quantity( $order, $order->get_item( $item_id ) ) );
	}

	public function test_refunds_accumulate_across_several_refunds(): void {
		list( $order, $item_id ) = $this->order_with_line( 597.00, 3 );

		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 0.00 );
		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 0.00 );

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 1, Miguel_Order_Refunds::get_entitled_quantity( $order, $order->get_item( $item_id ) ) );
	}

	public function test_nothing_is_refunded_when_the_order_has_no_miguel_line(): void {
		$order   = Miguel_Helper_Order::create_order();
		$item_id = key( $order->get_items() );
		$order->calculate_totals( false );
		$order->save();
		Miguel_Helper_Order::refund_line( $order, $item_id, 1, 10.00 );

		$never_miguel = function () {
			return false;
		};

		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $never_miguel ) );
	}

	public function test_everything_is_refunded_only_once_every_miguel_line_is_gone(): void {
		$first  = WC_Helper_Product::create_simple_product();
		$second = WC_Helper_Product::create_simple_product();

		$order          = Miguel_Helper_Order::create_order(); // Also holds a non-Miguel line, which stays.
		$first_item_id  = $order->add_product( $first, 1 );
		$second_item_id = $order->add_product( $second, 1 );
		$order->calculate_totals( false );
		$order->save();

		$miguel_ids = array( $first->get_id(), $second->get_id() );
		$is_miguel  = function ( $product ) use ( $miguel_ids ) {
			return in_array( $product->get_id(), $miguel_ids, true );
		};

		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'nothing refunded yet' );

		Miguel_Helper_Order::refund_line( $order, $first_item_id, 1, 10.00 );
		$this->assertFalse( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'one Miguel line remains' );

		Miguel_Helper_Order::refund_line( $order, $second_item_id, 0, 10.00 );
		$this->assertTrue( Miguel_Order_Refunds::has_refunded_all_miguel_items( wc_get_order( $order->get_id() ), $is_miguel ), 'every Miguel line is gone' );
	}

	/**
	 * An order with one product line of the given total and quantity, totals calculated.
	 *
	 * @param float $line_total Line total, without tax.
	 * @param int   $ordered    Quantity.
	 * @return array Order and line item ID.
	 */
	private function order_with_line( $line_total, $ordered ) {
		$product = WC_Helper_Product::create_simple_product();
		$order   = wc_create_order();
		$item_id = $order->add_product(
			$product,
			$ordered,
			array(
				'subtotal' => $line_total,
				'total'    => $line_total,
			)
		);
		$order->calculate_totals( false );
		$order->save();

		return array( $order, $item_id );
	}
}
