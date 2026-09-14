<?php
/**
 * Test the Miguel payment gateway.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Payment_Gateway extends Miguel_Test_Case {

	/**
	 * Put the gateway in WooCommerce's list the way a shop has it.
	 *
	 * The parent setUp() resets the plugin instance, which removes its hooks, the
	 * woocommerce_payment_gateways filter included. Re-creating the instance re-adds it, and
	 * re-initialising the gateways re-reads that filter and woocommerce_miguel_settings.
	 */
	public function setUp(): void {
		parent::setUp();

		Miguel::instance();
		WC()->payment_gateways()->init();
	}

	/**
	 * Drop saved gateway settings, so a renamed or disabled gateway does not leak into later tests.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_miguel_settings' );
		WC()->payment_gateways()->init();

		parent::tearDown();
	}

	/**
	 * The registered Miguel gateway.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( Miguel_Payment_Gateway::ID, $gateways, 'The Miguel gateway should be registered with WooCommerce.' );

		return $gateways[ Miguel_Payment_Gateway::ID ];
	}

	/**
	 * The gateway id is what the Miguel backend sends as payment_method.
	 */
	public function test_gateway_id_is_what_miguel_sends() {
		$this->assertSame( 'miguel', Miguel_Payment_Gateway::ID );
	}

	/**
	 * WooCommerce lists the gateway under its id.
	 */
	public function test_gateway_is_registered_with_woocommerce() {
		$this->assertInstanceOf( Miguel_Payment_Gateway::class, $this->get_gateway() );
	}

	/**
	 * A customer can never choose it at checkout.
	 */
	public function test_gateway_is_never_available_at_checkout() {
		$this->assertFalse( $this->get_gateway()->is_available() );
		$this->assertArrayNotHasKey(
			Miguel_Payment_Gateway::ID,
			WC()->payment_gateways()->get_available_payment_gateways()
		);
	}

	/**
	 * Enabled by default: that is what lists it in the order screen's payment dropdown.
	 */
	public function test_gateway_is_enabled_by_default() {
		$this->assertSame( 'yes', $this->get_gateway()->enabled );
	}

	/**
	 * The title is "Miguel" until the merchant renames it.
	 */
	public function test_title_defaults_to_miguel() {
		$this->assertSame( 'Miguel', $this->get_gateway()->get_title() );
	}

	/**
	 * A title saved in WooCommerce → Settings → Payments wins.
	 */
	public function test_title_comes_from_the_saved_settings() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Zaplaceno v aplikaci',
				'description' => '',
			)
		);
		WC()->payment_gateways()->init();

		$this->assertSame( 'Zaplaceno v aplikaci', $this->get_gateway()->get_title() );
	}

	/**
	 * Still never available when enabled explicitly.
	 */
	public function test_gateway_is_not_available_even_when_enabled_explicitly() {
		update_option( 'woocommerce_miguel_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();

		$this->assertFalse( $this->get_gateway()->is_available() );
	}

	/**
	 * The money is in Stripe, so WooCommerce offers no automatic refund.
	 */
	public function test_gateway_does_not_support_refunds() {
		$this->assertFalse( $this->get_gateway()->supports( 'refunds' ) );
		$this->assertTrue( $this->get_gateway()->supports( 'products' ) );
	}

	/**
	 * A checkout that ignored is_available() still takes no money.
	 */
	public function test_process_payment_fails_closed() {
		$order = Miguel_Helper_Order::create_order();

		$this->assertSame(
			array( 'result' => 'failure' ),
			$this->get_gateway()->process_payment( $order->get_id() )
		);

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	/**
	 * The plugin adds the gateway class to WooCommerce's list.
	 */
	public function test_register_payment_gateway_appends_the_class() {
		$this->assertSame(
			array( 'WC_Gateway_BACS', 'Miguel_Payment_Gateway' ),
			Miguel::instance()->register_payment_gateway( array( 'WC_Gateway_BACS' ) )
		);
	}

	/**
	 * The block integration, registered through the plugin's hook callback.
	 *
	 * @return Miguel_Payment_Gateway_Blocks
	 */
	private function get_blocks_integration() {
		$registry = new \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry();
		Miguel::instance()->register_payment_gateway_blocks( $registry );

		$this->assertTrue( $registry->is_registered( Miguel_Payment_Gateway::ID ) );

		$integration = $registry->get_registered( Miguel_Payment_Gateway::ID );
		$integration->initialize();

		return $integration;
	}

	/**
	 * Registered with the block checkout under the gateway id.
	 */
	public function test_blocks_integration_registers_under_the_gateway_id() {
		$integration = $this->get_blocks_integration();

		$this->assertInstanceOf( Miguel_Payment_Gateway_Blocks::class, $integration );
		$this->assertSame( 'miguel', $integration->get_name() );
	}

	/**
	 * Active while the gateway is enabled, which is the default.
	 */
	public function test_blocks_integration_is_active_by_default() {
		$this->assertTrue( $this->get_blocks_integration()->is_active() );
	}

	/**
	 * Inactive when the merchant disables the gateway: nothing is flagged then either.
	 */
	public function test_blocks_integration_is_inactive_when_gateway_is_disabled() {
		update_option( 'woocommerce_miguel_settings', array( 'enabled' => 'no' ) );

		$this->assertFalse( $this->get_blocks_integration()->is_active() );
	}

	/**
	 * Its script is what registers the method in JavaScript.
	 */
	public function test_blocks_integration_provides_its_script() {
		$this->assertSame(
			array( 'miguel-payment-method-blocks' ),
			$this->get_blocks_integration()->get_payment_method_script_handles()
		);
		$this->assertTrue( wp_script_is( 'miguel-payment-method-blocks', 'registered' ) );
	}

	/**
	 * The script gets the gateway's title.
	 */
	public function test_blocks_integration_passes_the_title_to_its_script() {
		update_option(
			'woocommerce_miguel_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Zaplaceno v aplikaci',
			)
		);

		$data = $this->get_blocks_integration()->get_payment_method_data();

		$this->assertSame( 'Zaplaceno v aplikaci', $data['title'] );
		$this->assertSame( array( 'products' ), $data['supports'] );
	}
}
