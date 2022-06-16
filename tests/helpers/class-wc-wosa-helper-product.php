<?php
/**
 * Product helper for tests
 *
 * @package WC_Wosa\Tests
 */
class WC_Wosa_Helper_Product {

  /**
   * @return WC_Product_Simple
   */
  public static function create_virtual_product() {
    $product = WC_Helper_Product::create_simple_product();
    $product->set_virtual( 'yes' );
    return $product;
  }

  /**
   * @return WC_Product_Simple
   */
  public static function create_downloadable_product() {
    $product = WC_Helper_Product::create_simple_product();
    $product->set_virtual( 'yes' );
    $product->set_downloadable( 'yes' );

    $files = array(
      md5( 'Dummy e-book' ) => array(
        'name' => 'Dummy e-book',
        'file' => '[wosa book="dummy-name" format="epub"]'
      )
    );

    $product->set_downloads( $files );
    $product->save();

    return $product;
  }

  /**
   * @param int $product_id
   * @param string $shortcode
   */
  public static function set_product_file_url( $product_id, $shortcode ) {
    $product = wc_get_product( $product_id );
    $downloads = $product->get_downloads();

    foreach( $downloads as $hash => $file ) {
      $downloads[$hash]['file'] = $shortcode;
    }

    $product->set_downloads( $downloads );
    $product->save();
  }

  /**
   * @param int $product_id
   */
  public static function delete_product( $product_id ) {
    WC_Helper_Product::delete_product( $product_id );
  }
}
