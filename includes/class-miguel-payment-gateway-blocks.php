<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the Miguel payment method with the block checkout, so that it is never offered there.
 *
 * WooCommerce's Checkout block editor lists every enabled gateway that has no payment method
 * registered in JavaScript as incompatible with the block checkout. The Miguel gateway is
 * enabled on purpose (that is what lists it in the order screen's payment dropdown), so this
 * registers a method whose canMakePayment() is always false: the editor counts it as
 * compatible, and the checkout never offers it.
 *
 * @package Miguel
 */
final class Miguel_Payment_Gateway_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Payment method name; the gateway id.
	 *
	 * @var string
	 */
	protected $name = Miguel_Payment_Gateway::ID;

	/**
	 * Read the gateway's settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Miguel_Payment_Gateway::ID . '_settings', array() );
	}

	/**
	 * Active while the gateway is enabled — exactly when the editor would otherwise flag it.
	 *
	 * @return bool
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', 'yes' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * The script that registers the method in JavaScript.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'miguel-payment-method-blocks',
			plugin_dir_url( MIGUEL_PLUGIN_FILE ) . 'assets/js/payment-method-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			miguel()->version,
			true
		);

		return array( 'miguel-payment-method-blocks' );
	}

	/**
	 * Data the script reads.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'    => $this->get_setting( 'title', __( 'Miguel', 'miguel' ) ),
			'supports' => array( 'products' ),
		);
	}
}
