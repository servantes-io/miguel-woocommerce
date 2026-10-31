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

	/**
	 * Callback ids of the given hooks by priority, in order.
	 *
	 * @param string[] $names Hook names.
	 * @return array
	 */
	private function hook_layout( $names ) {
		global $wp_filter;

		$layout = array();
		foreach ( $names as $name ) {
			$hook = $wp_filter[ $name ];
			foreach ( $hook->callbacks as $priority => $entries ) {
				$layout[ $name ][ $priority ] = array_keys( $entries );
			}
		}

		return $layout;
	}

	/**
	 * Hook names that have a callback which is a method of the object.
	 *
	 * @param object $object Object.
	 * @return string[]
	 */
	private function hooks_bound_to( $object ) {
		global $wp_filter;

		$names = array();
		foreach ( $wp_filter as $name => $hook ) {
			foreach ( $hook->callbacks as $entries ) {
				foreach ( $entries as $entry ) {
					if ( is_array( $entry['function'] ) && $entry['function'][0] === $object ) {
						$names[ $name ] = $name;
					}
				}
			}
		}

		return array_values( $names );
	}

	/**
	 * The WC_Cart_Session of a cart.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return WC_Cart_Session
	 */
	private function cart_session( $cart ) {
		$property = new ReflectionProperty( WC_Cart::class, 'session' );
		$property->setAccessible( true );

		return $property->getValue( $cart );
	}

	public function test_persistent_cart_meta_is_not_written() {
		$user_id = self::factory()->user->create();
		$key     = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$before  = get_user_meta( $user_id, $key, true );

		Miguel_Isolated_Cart::run(
			$this->customer( array( 'user_id' => $user_id ) ),
			function ( $cart ) {
				$cart->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
			}
		);

		$this->assertSame( $before, get_user_meta( $user_id, $key, true ) );
	}

	public function test_throwaway_cart_leaves_no_hooks_and_original_hooks_are_restored() {
		$original      = WC()->cart;
		$names         = array_merge( $this->hooks_bound_to( $original ), $this->hooks_bound_to( $this->cart_session( $original ) ) );
		$layout_before = $this->hook_layout( $names );
		$product_id    = WC_Helper_Product::create_simple_product()->get_id();
		$throwaway     = null;

		Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) use ( &$throwaway, $product_id ) {
				$throwaway = $cart;
				$cart->add_to_cart( $product_id );
			}
		);

		$this->assertSame( array(), $this->hooks_bound_to( $throwaway ) );
		$this->assertSame( array(), $this->hooks_bound_to( $this->cart_session( $throwaway ) ) );
		$this->assertNotSame( array(), $this->hooks_bound_to( $original ) );
		$this->assertSame( $layout_before, $this->hook_layout( $names ) );
	}

	public function test_original_cart_callbacks_fire_after_the_call() {
		$original = WC()->cart;
		$fired    = 0;
		$count    = function ( $cart ) use ( $original, &$fired ) {
			if ( $cart === $original ) {
				++$fired;
			}
		};
		add_action( 'woocommerce_before_calculate_totals', $count );

		Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) {
				$cart->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
			}
		);

		// WC_Cart hooks calculate_totals alone at priority 20 of woocommerce_add_to_cart, so
		// detaching empties that bucket; adding to the real cart must still recalculate it.
		$original->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
		remove_action( 'woocommerce_before_calculate_totals', $count );

		$this->assertSame( 1, $fired );
	}

	public function test_original_cart_does_not_react_during_the_call() {
		$original_cart = WC()->cart;
		$contents      = $original_cart->get_cart_contents();
		$totals        = $original_cart->get_totals();
		$inside        = null;

		Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) use ( $original_cart, &$inside ) {
				$cart->add_to_cart( WC_Helper_Product::create_simple_product()->get_id() );
				$inside = array(
					$this->hooks_bound_to( $original_cart ),
					$this->hooks_bound_to( $this->cart_session( $original_cart ) ),
				);
			}
		);

		$this->assertSame( array( array(), array() ), $inside );
		$this->assertSame( $contents, $original_cart->get_cart_contents() );
		$this->assertSame( $totals, $original_cart->get_totals() );
	}

	public function test_throwaway_cart_keeps_set_session_but_not_cookie_callbacks_during_the_call() {
		$inside = null;

		Miguel_Isolated_Cart::run(
			$this->customer(),
			function ( $cart ) use ( &$inside ) {
				$inside = $this->hooks_bound_to( $this->cart_session( $cart ) );
			}
		);

		$this->assertContains( 'woocommerce_after_calculate_totals', $inside );
		$this->assertNotContains( 'woocommerce_add_to_cart', $inside );
		$this->assertNotContains( 'shutdown', $inside );
		$this->assertNotContains( 'wp', $inside );
	}

	public function test_billing_address_and_empty_addresses() {
		$run = Miguel_Isolated_Cart::run(
			$this->customer(
				array(
					'billing'  => array(
						'country'  => 'SK',
						'state'    => '',
						'city'     => 'Bratislava',
						'postcode' => '81101',
					),
					'shipping' => array(),
				)
			),
			function () {
				$c = WC()->customer;
				return array( $c->get_billing_country(), $c->get_billing_city(), $c->get_billing_postcode(), $c->get_shipping_country() );
			}
		);

		// No shipping address: the goods go to the billing address, as on the web.
		$this->assertSame( array( 'SK', 'Bratislava', '81101', 'SK' ), $run['result'] );
	}

	public function test_addresses_not_sent_are_the_default_customer_location() {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_default_customer_address', 'base' );

		$read = function () {
			$c = WC()->customer;
			return array( $c->get_billing_country(), $c->get_billing_state(), $c->get_shipping_country(), $c->get_shipping_state(), $c->get_shipping_city() );
		};

		$none = Miguel_Isolated_Cart::run( $this->customer(), $read );
		$this->assertSame( array( 'US', 'CA', 'US', 'CA', '' ), $none['result'] );

		$shipping_only = Miguel_Isolated_Cart::run(
			$this->customer(
				array(
					'shipping' => array(
						'country' => 'CZ',
						'city'    => 'Praha',
					),
				)
			),
			$read
		);
		$this->assertSame( array( 'US', 'CA', 'CZ', '', 'Praha' ), $shipping_only['result'] );
	}
}
