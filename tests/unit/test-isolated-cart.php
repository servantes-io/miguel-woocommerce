<?php
/**
 * Tests for Miguel_Isolated_Cart.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Isolated_Cart extends Miguel_Test_Case {

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
	 * Assert the real cart, session, customer, notices and user are what they were.
	 *
	 * @param array $before State from snapshot().
	 */
	private function assert_restored( $before ) {
		$this->assertSame( $before['cart'], WC()->cart );
		$this->assertSame( $before['contents'], WC()->cart->get_cart_contents() );
		$this->assertSame( $before['session'], WC()->session );
		$this->assertSame( $before['customer'], WC()->customer );
		$this->assertSame( $before['user'], get_current_user_id() );
		$notices = wc_get_notices();
		$this->assertSame( array( 'success' ), array_keys( $notices ) );
		$this->assertCount( 1, $notices['success'] );
		$this->assertSame( 'real', $notices['success'][0]['notice'] );
	}

	/**
	 * Put a product and a notice into the real cart/session and remember the state.
	 *
	 * @return array
	 */
	private function snapshot() {
		$product = WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		wc_clear_notices();
		wc_add_notice( 'real' );

		return array(
			'cart'     => WC()->cart,
			'contents' => WC()->cart->get_cart_contents(),
			'session'  => WC()->session,
			'customer' => WC()->customer,
			'user'     => get_current_user_id(),
		);
	}

	public function test_work_sees_a_fresh_cart_session_and_customer() {
		$original = WC()->cart;
		$seen     = Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) use ( $original ) {
				return array(
					$cart === WC()->cart,
					$cart !== $original,
					WC()->session instanceof Miguel_Memory_Session,
					WC()->customer->get_billing_email(),
					WC()->customer->get_id(),
					WC()->session->get( 'chosen_payment_method' ),
				);
			}
		);

		$this->assertSame( array( true, true, true, 'buyer@example.com', 0, 'miguel' ), $seen['result'] );
	}

	public function test_shipping_method_and_addresses_reach_session_and_customer() {
		$run = Miguel_Isolated_Cart::run(
			$this->customer(
				array(
					'shipping_method' => 'flat_rate:3',
					'shipping'        => array(
						'country'  => 'CZ',
						'city'     => 'Praha',
						'postcode' => '11000',
						'state'    => '',
					),
				)
			),
			function () {
				return array(
					WC()->session->get( 'chosen_shipping_methods' ),
					WC()->customer->get_shipping_country(),
					WC()->customer->get_shipping_city(),
				);
			}
		);
		$this->assertSame( array( array( 'flat_rate:3' ), 'CZ', 'Praha' ), $run['result'] );

		$run = Miguel_Isolated_Cart::run(
			$this->customer(),
			function () {
				return WC()->session->get( 'chosen_shipping_methods' );
			}
		);
		$this->assertNull( $run['result'] );
	}

	public function test_current_user_is_the_customer_for_the_call() {
		$user_id  = self::factory()->user->create();
		$previous = get_current_user_id();

		$run = Miguel_Isolated_Cart::run(
			$this->customer( array( 'user_id' => $user_id ) ),
			function () {
				return get_current_user_id();
			}
		);
		$this->assertSame( $user_id, $run['result'] );
		$this->assertSame( $previous, get_current_user_id() );

		wp_set_current_user( $user_id );
		$run = Miguel_Isolated_Cart::run(
			$this->customer(),
			function () {
				return get_current_user_id();
			}
		);
		$this->assertSame( 0, $run['result'] );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	public function test_everything_is_restored_after_the_call() {
		$before = $this->snapshot();

		Miguel_Isolated_Cart::run(
			$this->customer( array( 'user_id' => self::factory()->user->create() ) ),
			function ( $cart ) {
				$cart->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
				wc_add_notice( 'inner' );
			}
		);

		$this->assert_restored( $before );
	}

	public function test_everything_is_restored_when_work_throws() {
		$before = $this->snapshot();

		try {
			Miguel_Isolated_Cart::run(
				$this->customer( array( 'user_id' => self::factory()->user->create() ) ),
				function () {
					wc_add_notice( 'inner' );
					throw new Error( 'boom' );
				}
			);
			$this->fail( 'Expected Error was not thrown.' );
		} catch ( Error $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		} finally {
			$this->assert_restored( $before );
		}
	}

	public function test_notices_are_returned_not_kept() {
		wc_clear_notices();
		$run = Miguel_Isolated_Cart::run(
			$this->customer(),
			function () {
				wc_add_notice( '<b>Kupón &amp; dárek</b>', 'success' );
				wc_add_notice( '<b>Kupón &amp; dárek</b>', 'success' );
				wc_add_notice( 'Chyba', 'error' );
			}
		);

		$this->assertSame( array( 'Kupón & dárek', 'Chyba' ), $run['notices'] );
		$this->assertSame( array(), wc_get_notices() );
	}

	public function test_nothing_is_written_to_the_sessions_table() {
		global $wpdb;
		$table  = $wpdb->prefix . 'woocommerce_sessions';
		$before = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) {
				$cart->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
				$cart->calculate_totals();
			}
		);

		$this->assertSame( $before, $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
