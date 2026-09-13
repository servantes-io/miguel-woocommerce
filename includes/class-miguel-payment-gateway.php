<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The payment method of the orders Miguel creates in the shop.
 *
 * A customer pays for these orders in a Miguel app, through Stripe run by Miguel, and Miguel
 * then creates the order here with payment method "miguel". Without a gateway of that id,
 * WooCommerce shows the order's payment method as "Other". This gateway exists only to give
 * those orders a name: it is never available at checkout and takes no money.
 *
 * @package Miguel
 */
class Miguel_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * Gateway id, and the payment_method Miguel sends when it creates an order.
	 */
	const ID = 'miguel';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'Miguel', 'miguel' );
		$this->method_description = __( 'Orders placed and paid in a Miguel app. Miguel creates these orders itself; this method is never offered at checkout.', 'miguel' );
		$this->has_fields         = false;
		// No 'refunds': the money is in Stripe, so a refund in WooCommerce stays a manual one.
		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// WooCommerce constructs gateways itself, outside the plugin's hook manager; this is the
		// standard way every gateway saves its settings screen.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings shown in WooCommerce → Settings → Payments → Miguel.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'miguel' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Miguel as the payment method of orders from the Miguel app', 'miguel' ),
				// Enabled is what lists the gateway in the order screen's payment dropdown.
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'miguel' ),
				'type'        => 'text',
				'description' => __( 'The payment method name shown on orders from the Miguel app.', 'miguel' ),
				'default'     => __( 'Miguel', 'miguel' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => __( 'Description', 'miguel' ),
				'type'    => 'textarea',
				'default' => '',
			),
		);
	}

	/**
	 * Never available: Miguel creates these orders itself, a customer never picks this at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		return false;
	}

	/**
	 * Take no money. Unreachable while is_available() is false; a checkout that ignores it fails closed.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		return array( 'result' => 'failure' );
	}
}
