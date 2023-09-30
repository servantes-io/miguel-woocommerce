<?php
/**
 * Test Generate Request
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Request extends WP_UnitTestCase {

	/**
	 * Test get_args(), guest
	 */
	public function test_get_args(): void {
		$order = Miguel_Helper_Order::create_order();

		$want = array(
			'user' => array(
				'id' => md5( $order->get_billing_email() ),
				'email' => $order->get_billing_email(),
				'full_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_city(),
			),
			'purchase_date' => $order->get_date_paid()->format( 'Y-m-d' ),
		);

		$request = new Miguel_Request( $order );

		$this->assertEquals( $want, $request->to_array() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * Test get_args(), customer exists
	 */
	public function test_get_args__customer(): void {
		$order = Miguel_Helper_Order::create_order();

		$customer_id = $this->factory->user->create(
			array(
				'role' => 'customer',
			)
		);

		$customer = get_user_by( 'id', $customer_id );
		$order->set_customer_id( $customer_id );

		$want = array(
			'user' => array(
				'id' => md5( $customer->user_email ),
				'email' => $customer->user_email,
				'full_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_city(),
			),
			'purchase_date' => $order->get_date_paid()->format( 'Y-m-d' ),
		);

		$request = new Miguel_Request( $order );

		$this->assertEquals( $want, $request->to_array() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
		wp_delete_user( $customer_id );
	}

	/**
	 * Test is_valid()
	 */
	public function test_is_valid(): void {
		$order = Miguel_Helper_Order::create_order();
		$request = new Miguel_Request( $order );

		$this->assertEquals( true, $request->is_valid() );

		$order->set_date_paid( null );
		$order->save();

		$this->assertEquals( false, $request->is_valid() );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
