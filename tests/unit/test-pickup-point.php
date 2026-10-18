<?php
/**
 * Test translating Miguel's neutral pickup point into the shop's carrier plugin meta (SMP-23).
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Pickup_Point extends Miguel_Test_Case {

	/**
	 * Messages the spy logger received: each array( level, message, source ).
	 *
	 * @var array
	 */
	private $logged = array();

	public function setUp(): void {
		parent::setUp();

		$this->logged = array();
		$logged       = &$this->logged;
		$handler      = new class( $logged ) implements WC_Log_Handler_Interface {
			private $logged;

			public function __construct( &$logged ) {
				$this->logged = &$logged;
			}

			public function handle( $timestamp, $level, $message, $context ) {
				$this->logged[] = array( $level, $message, $context['source'] ?? '' );
				return true;
			}
		};
		$logger = new WC_Logger( array( $handler ), 'debug' );
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);

		update_option( 'active_plugins', array() );
	}

	public function tearDown(): void {
		parent::tearDown();

		// wc_get_logger() keeps the last logger in a static; hand it a plain one again.
		$logger = new WC_Logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);
		wc_get_logger();
		remove_all_filters( 'woocommerce_logging_class' );
	}

	/**
	 * Activate a carrier plugin the way WordPress records it.
	 *
	 * @param string $plugin Plugin basename, e.g. wc-zasilkovna/wc-zasilkovna.php.
	 */
	private function activate( $plugin ) {
		update_option( 'active_plugins', array( $plugin ) );
	}

	/**
	 * The payload Miguel sends for a printed book delivered to a pickup point.
	 *
	 * @param string     $idempotency_key Idempotency key.
	 * @param mixed      $pickup_point    The neutral entry's value, or null for none.
	 * @return array
	 */
	private function pickup_order_payload( $idempotency_key, $pickup_point ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'smp23-' . $idempotency_key );
		$product->save();

		$payload = array(
			'idempotency_key' => $idempotency_key,
			'payment_method'  => 'miguel',
			'status'          => 'processing',
			'user_email'      => 'buyer@example.com',
			'billing'         => array(
				'first_name' => 'Jan Novák',
				'address_1'  => 'Václavské náměstí 1',
				'city'       => 'Praha',
				'postcode'   => '11000',
				'country'    => 'CZ',
				'email'      => 'buyer@example.com',
			),
			'shipping'        => array(
				'first_name' => 'Jan Novák',
				'country'    => 'CZ',
			),
			'shipping_lines'  => array(
				array(
					'method_id'    => 'flat_rate',
					'method_title' => 'Výdejní místo',
					'total'        => '79.00',
				),
			),
			'line_items'      => array(
				array(
					'product_id' => $product->get_id(),
					'quantity'   => 1,
				),
			),
		);

		if ( null !== $pickup_point ) {
			$payload['meta_data'] = array(
				array(
					'key'   => '_miguel_pickup_point',
					'value' => $pickup_point,
				),
			);
		}

		return $payload;
	}

	/**
	 * A neutral entry's value.
	 *
	 * @param string      $carrier Carrier.
	 * @param string      $id      Point id.
	 * @param string|null $address Address, or null to leave it out.
	 * @return array
	 */
	private function point( $carrier, $id, $address = 'Náměstí 1, 11000 Praha' ) {
		$point = array(
			'carrier' => $carrier,
			'id'      => $id,
			'name'    => 'Praha 1, Náměstí 1',
		);
		if ( null !== $address ) {
			$point['address'] = $address;
		}

		return $point;
	}

	/**
	 * POST an order payload through the Miguel create API and return the created order.
	 *
	 * @param array $payload Request body.
	 * @return WC_Order
	 */
	private function post_order( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/miguel/v1/orders' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = ( new Miguel_Order_Create_Api( new Miguel_Hook_Manager() ) )->create_order( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$this->assertContains( $response->get_status(), array( 200, 201 ) );

		return wc_get_order( $response->get_data()['id'] );
	}

	/**
	 * Warnings the spy logger received from source miguel.
	 *
	 * @return array Messages.
	 */
	private function miguel_warnings() {
		$warnings = array();
		foreach ( $this->logged as $entry ) {
			if ( 'warning' === $entry[0] && 'miguel' === $entry[2] ) {
				$warnings[] = $entry[1];
			}
		}

		return $warnings;
	}

	public function test_packeta_writes_zasilkovna_post_meta_and_shipmall_meta() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-packeta', $this->point( 'packeta', '12345' ) ) );

		$this->assertSame( '12345', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertSame( 'Praha 1, Náměstí 1', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_name', true ) );
		$this->assertSame( '12345', $order->get_meta( 'ftx_shm_packeta_id' ) );
		$this->assertSame( 'Praha 1, Náměstí 1', $order->get_meta( 'ftx_shm_packeta_name' ) );
		$this->assertSame( '12345', $order->get_meta( '_miguel_pickup_point' )['id'] );
		$this->assertSame( array(), $this->miguel_warnings() );
	}

	public function test_balikovna_writes_toret_meta_with_b_prefix() {
		$this->activate( 'toret-ceska-posta/toret-ceska-posta.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-balikovna', $this->point( 'balikovna', '11000' ) ) );

		$this->assertSame( 'b11000', $order->get_meta( 'tcp_pickup_id' ) );
		$this->assertSame( 'b11000', $order->get_meta( 'tcp_pickup_linkid' ) );
		$this->assertSame( 'Praha 1, Náměstí 1', $order->get_meta( 'tcp_pickup_name' ) );
		$this->assertSame( 'Praha 1, Náměstí 1', $order->get_meta( 'tcp_pickup_parcelshopname' ) );
		$this->assertSame( 'Náměstí 1, 11000 Praha', $order->get_meta( 'tcp_pickup_adresa' ) );
		$this->assertSame( 'balikovna', $order->get_meta( '_miguel_pickup_point' )['carrier'] );
		$this->assertSame( array(), $this->miguel_warnings() );
	}

	public function test_balikovna_keeps_prefixed_id() {
		$this->activate( 'toret-ceska-posta/toret-ceska-posta.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-balikovna-b', $this->point( 'balikovna', 'b11000' ) ) );

		$this->assertSame( 'b11000', $order->get_meta( 'tcp_pickup_id' ) );
	}

	public function test_balikovna_without_address_writes_no_address() {
		$this->activate( 'toret-ceska-posta/toret-ceska-posta.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-balikovna-noaddr', $this->point( 'balikovna', '11000', null ) ) );

		$this->assertSame( 'b11000', $order->get_meta( 'tcp_pickup_id' ) );
		$this->assertFalse( $order->meta_exists( 'tcp_pickup_adresa' ) );
	}

	public function test_gls_writes_gls_id_and_data() {
		$this->activate( 'wpify-woo-gls/wpify-woo-gls.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-gls', $this->point( 'gls', 'CZ12345-PS' ) ) );

		$this->assertSame( 'CZ12345-PS', $order->get_meta( '_gls_id' ) );
		$this->assertSame(
			array(
				'id'      => 'CZ12345-PS',
				'name'    => 'Praha 1, Náměstí 1',
				'address' => 'Náměstí 1, 11000 Praha',
				'zipcode' => '',
				'city'    => '',
			),
			$order->get_meta( '_gls_data' )
		);
		$this->assertSame( array(), $this->miguel_warnings() );
	}

	public function test_gls_without_address_writes_empty_address() {
		$this->activate( 'wpify-woo-gls/wpify-woo-gls.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-gls-noaddr', $this->point( 'gls', 'CZ12345-PS', null ) ) );

		$this->assertSame( '', $order->get_meta( '_gls_data' )['address'] );
	}

	public function test_carrier_meta_is_on_the_order_when_its_status_changes() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );
		$seen = null;
		add_action(
			'woocommerce_order_status_changed',
			function ( $order_id ) use ( &$seen ) {
				$fresh = wc_get_order( $order_id );
				$seen  = array(
					'post_meta'  => get_post_meta( $order_id, '_wc_zasilkovna_point_id', true ),
					'order_meta' => $fresh->get_meta( 'ftx_shm_packeta_id' ),
				);
			}
		);

		$this->post_order( $this->pickup_order_payload( 'smp23-status', $this->point( 'packeta', '12345' ) ) );

		$this->assertNotNull( $seen, 'the order status did not change during creation' );
		$this->assertSame( '12345', $seen['post_meta'] );
		$this->assertSame( '12345', $seen['order_meta'] );
	}

	public function test_without_active_plugin_logs_warning_and_keeps_only_neutral_entry() {
		$order = $this->post_order( $this->pickup_order_payload( 'smp23-noplugin', $this->point( 'packeta', '12345' ) ) );

		$this->assertSame( '', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertSame( '12345', $order->get_meta( '_miguel_pickup_point' )['id'] );

		$warnings = $this->miguel_warnings();
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( (string) $order->get_order_number(), $warnings[0] );
		$this->assertStringContainsString( 'packeta', $warnings[0] );
	}

	public function test_plugin_of_another_carrier_does_not_translate() {
		$this->activate( 'wpify-woo-gls/wpify-woo-gls.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-othercarrier', $this->point( 'balikovna', '11000' ) ) );

		$this->assertFalse( $order->meta_exists( 'tcp_pickup_id' ) );
		$this->assertFalse( $order->meta_exists( '_gls_id' ) );
		$this->assertCount( 1, $this->miguel_warnings() );
	}

	public function test_plugin_with_similar_directory_is_not_active() {
		$this->activate( 'wc-zasilkovna-pro/wc-zasilkovna.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-similar', $this->point( 'packeta', '12345' ) ) );

		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertCount( 1, $this->miguel_warnings() );
	}

	public function test_unknown_carrier_logs_warning() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-unknown', $this->point( 'ppl', 'KM1234' ) ) );

		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertSame( 'ppl', $order->get_meta( '_miguel_pickup_point' )['carrier'] );
		$warnings = $this->miguel_warnings();
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'ppl', $warnings[0] );
	}

	public function test_entry_without_id_logs_warning() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );
		$point = $this->point( 'packeta', '' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-noid', $point ) );

		$this->assertSame( '', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertCount( 1, $this->miguel_warnings() );
	}

	public function test_order_without_entry_gets_no_carrier_meta_and_no_warning() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-none', null ) );

		$this->assertFalse( $order->meta_exists( '_miguel_pickup_point' ) );
		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertSame( '', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertSame( array(), $this->miguel_warnings() );
	}

	public function test_replay_writes_no_further_meta() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );
		$payload = $this->pickup_order_payload( 'smp23-replay', $this->point( 'packeta', '12345' ) );

		$first  = $this->post_order( $payload );
		$second = $this->post_order( $payload );

		$this->assertSame( $first->get_id(), $second->get_id() );
		$this->assertCount( 1, $second->get_meta( 'ftx_shm_packeta_id', false ) );
		$this->assertCount( 1, $second->get_meta( '_miguel_pickup_point', false ) );
		$this->assertCount( 1, get_post_meta( $second->get_id(), '_wc_zasilkovna_point_id', false ) );
	}

	public function test_order_created_by_another_plugin_during_the_request_gets_no_post_meta() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );
		$other = null;
		add_action(
			'woocommerce_order_status_changed',
			function () use ( &$other ) {
				if ( null === $other ) {
					$other = wc_create_order();
				}
			}
		);

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-other-order', $this->point( 'packeta', '12345' ) ) );

		$this->assertNotNull( $other, 'the order status did not change during creation' );
		$this->assertSame( '12345', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertSame( '', get_post_meta( $other->get_id(), '_wc_zasilkovna_point_id', true ) );
	}

	public function test_value_that_is_not_an_object_logs_warning() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );

		$order = $this->post_order( $this->pickup_order_payload( 'smp23-string', '{"carrier":"packeta","id":"12345"}' ) );

		$this->assertFalse( $order->meta_exists( 'ftx_shm_packeta_id' ) );
		$this->assertSame( '', get_post_meta( $order->get_id(), '_wc_zasilkovna_point_id', true ) );
		$this->assertCount( 1, $this->miguel_warnings() );
	}

	public function test_post_meta_callback_is_removed_after_the_request() {
		$this->activate( 'wc-zasilkovna/wc-zasilkovna.php' );
		$this->post_order( $this->pickup_order_payload( 'smp23-callback', $this->point( 'packeta', '12345' ) ) );

		$later = wc_create_order();

		$this->assertSame( '', get_post_meta( $later->get_id(), '_wc_zasilkovna_point_id', true ) );
	}
}

/**
 * The same tests with HPOS on and no sync to the posts table: wp_postmeta then holds only what is
 * written there explicitly, which is what wc-zasilkovna reads.
 */
class Test_Miguel_Pickup_Point_Hpos extends Test_Miguel_Pickup_Point {
	use \Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;

	public function setUp(): void {
		parent::setUp();
		$this->setup_cot();
		$this->disable_cot_sync();
	}

	public function tearDown(): void {
		// The orders a test created exist in the orders table alone; switching back is fine here.
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->clean_up_cot_setup();
		parent::tearDown();
	}
}

/**
 * The same tests with HPOS on and the posts table kept in sync.
 */
class Test_Miguel_Pickup_Point_Hpos_Sync extends Test_Miguel_Pickup_Point {
	use \Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;

	public function setUp(): void {
		parent::setUp();
		$this->setup_cot();
		$this->enable_cot_sync();
	}

	public function tearDown(): void {
		// The orders a test created exist in the orders table alone; switching back is fine here.
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->clean_up_cot_setup();
		parent::tearDown();
	}
}
