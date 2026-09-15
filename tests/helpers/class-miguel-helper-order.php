<?php
/**
 * Order helper for tests
 *
 * @package Miguel\Tests
 */
class Miguel_Helper_Order {

	/**
	 * @return WC_Order
	 */
	public static function create_order() {
		$product = Miguel_Helper_Product::create_virtual_product();

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$order = wc_create_order(
			array(
				'status' => 'processing',
				'customer_id' => 0,
				'customer_note' => '',
				'total' => '',
			)
		);

		$billing_address = array(
			'country' => 'CZ',
			'first_name' => 'Jan',
			'last_name' => 'Miguel',
			'company' => 'Jan Miguel Publishing',
			'address_1' => 'Roubalova',
			'address_2' => '',
			'postcode' => '60200',
			'city' => 'Brno',
			'state' => 'Jihomoravsky kraj',
			'email' => 'test@melvil.cz',
			'phone' => '555-32123',
		);

		$order->add_product( $product, 1 );
		$order->set_address( $billing_address, 'billing' );
		$order->set_date_paid( '2018-09-20 00:00:00' );
		$order->save();

		return $order;
	}

	/**
	 * @return array
	 */
	public static function create_order_downloadable() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$order = self::create_order();

		$item_id = $order->add_product( $product, 1 );
		$order->payment_complete();
		$order->save();

		$item = $order->get_item( $item_id );
		$product_obj = $item->get_product();
		$downloads = $product_obj ? $product_obj->get_downloads() : array();
		$download = reset( $downloads );
		$download_url = is_array( $download ) ? ( isset( $download['file'] ) ? $download['file'] : '' ) : ( method_exists( $download, 'get_file' ) ? $download->get_file() : '' );

		return array(
			'order_id' => $order->get_id(),
			'product_id' => $product->get_id(),
			'download_url' => $download_url,
		);
	}

	/**
	 * Refund part of one line the way the admin refund form does.
	 *
	 * The order's totals must be calculated first ($order->calculate_totals( false )), because
	 * WooCommerce refuses a refund larger than what is left to refund. Orders loaded before the
	 * refund see it straight away.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Line item ID.
	 * @param int      $qty     Quantity to refund; 0 for an amount-only refund.
	 * @param float    $amount  Amount to refund on the line, without tax.
	 * @return WC_Order_Refund
	 * @throws Exception When WooCommerce refuses the refund.
	 */
	public static function refund_line( $order, $item_id, $qty, $amount ) {
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => array(
					$item_id => array(
						'qty'          => $qty,
						'refund_total' => $amount,
					),
				),
			)
		);

		if ( is_wp_error( $refund ) ) {
			throw new Exception( 'Refund failed: ' . $refund->get_error_message() );
		}

		return $refund;
	}

	/**
	 * @param int $order_id
	 */
	public static function delete_order( $order_id ) {
		WC_Helper_Order::delete_order( $order_id );
	}
}
