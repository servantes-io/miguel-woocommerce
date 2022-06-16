<?php
/**
 * Order helper for tests
 *
 * @package WC_Wosa\Tests
 */
class WC_Wosa_Helper_Order {

  /**
   * @return WC_Order
   */
  public static function create_order() {
    $product = WC_Wosa_Helper_Product::create_virtual_product();

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    $order = wc_create_order( array(
      'status' => 'processing',
      'customer_id' => 0,
      'customer_note' => '',
      'total' => '',
    ) );

    $billing_address = array(
      'country' => 'CZ',
      'first_name' => 'Jan',
      'last_name' => 'Wosa',
      'company' => 'Jan Wosa Publishing',
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
    $product = WC_Wosa_Helper_Product::create_downloadable_product(); 
    $order = self::create_order();

    $item_id = $order->add_product( $product, 1 );
    $order->payment_complete();
    $order->save();

    $item = $order->get_item( $item_id ); 
    $downloads = $item->get_item_downloads();
    $download = reset( $downloads );


    return array(
      'order_id' => $order->id,
      'product_id' => $product->id,
      'download_url' => $download->get_file()
    );
  }

  /**
   * @param int $order_id
   */
  public static function delete_order( $order_id ) {
    WC_Helper_Order::delete_order( $order_id );
  }
}
