<?php
/**
 * Product helper for tests
 *
 * @package Miguel\Tests
 */
class Miguel_Helper_Product {

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

		// Save product first with no downloads to avoid validation
		$product->save();

		// Set downloads directly via meta data to bypass WooCommerce validation
		$downloads = array(
			'dummy_epub_' . wp_generate_uuid4() => array(
				'name' => 'Dummy e-book',
				'file' => '[miguel id="dummy-name" format="epub"]',
			),
			'dummy_mobi_' . wp_generate_uuid4() => array(
				'name' => 'Dummy e-book',
				'file' => '[miguel id="dummy-name" format="mobi"]',
			),
		);

		// Bypass WooCommerce validation by setting meta directly
		update_post_meta( $product->get_id(), '_downloadable_files', $downloads );

		return $product;
	}

	/**
	 * @param int    $product_id
	 * @param string $shortcode
	 */
	public static function set_product_file_url( $product_id, $shortcode ) {
		$product = wc_get_product( $product_id );
		$downloads = $product->get_downloads();

		foreach ( $downloads as $hash => $file ) {
			$downloads[ $hash ]['file'] = $shortcode;
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
