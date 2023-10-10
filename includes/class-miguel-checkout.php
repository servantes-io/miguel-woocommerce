<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Checkout
 *
 * @package Miguel_Checkout
 */

class Miguel_Checkout {

	/**
	 * Add hooks
	 */
	public function __construct() {
		add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ), 10 );
	}

	/**
	 * @param int $order_id
	 */
	public function payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! $order->is_download_permitted() ) {
			return;
		}

		miguel_send_order( $order );
	}
}

return new Miguel_Checkout();
