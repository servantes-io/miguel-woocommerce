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

		// Temporarily disable download URL validation during tests
		add_filter( 'woocommerce_downloadable_file_allowed', '__return_true' );

		$download_epub = new WC_Product_Download();
		$download_epub->set_name( 'Dummy e-book' );
		$download_epub->set_file( '[miguel id="dummy-name" format="epub"]' );

		$download_mobi = new WC_Product_Download();
		$download_mobi->set_name( 'Dummy e-book' );
		$download_mobi->set_file( '[miguel id="dummy-name" format="mobi"]' );

		$downloads = array(
			'dummy_epub_' . wp_generate_uuid4() => $download_epub,
			'dummy_mobi_' . wp_generate_uuid4() => $download_mobi,
		);

		$product->set_downloads( $downloads );
		$product->save();

		// Re-enable download URL validation
		remove_filter( 'woocommerce_downloadable_file_allowed', '__return_true' );

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
