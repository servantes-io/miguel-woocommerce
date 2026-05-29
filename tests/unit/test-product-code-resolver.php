<?php
/**
 * Test Miguel product code resolver.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Code_Resolver extends Miguel_Test_Case {

	/**
	 * Test that resolver maps shortcode codes and SKU fallback.
	 */
	public function test_get_product_code_details_map_reads_shortcodes_and_sku_fallback() {
		$shortcode_product = Miguel_Helper_Product::create_downloadable_product();

		$sku_product = WC_Helper_Product::create_simple_product();
		$sku_product->set_sku( 'sku-fallback-1' );
		$sku_product->save();

		$resolver = new Miguel_Product_Code_Resolver();
		$details_map = $resolver->get_product_code_details_map();

		$this->assertArrayHasKey( 'dummy-name', $details_map );
		$this->assertEquals( $shortcode_product->get_id(), $details_map['dummy-name']['product_id'] );
		$this->assertArrayHasKey( 'sku-fallback-1', $details_map );
		$this->assertEquals( $sku_product->get_id(), $details_map['sku-fallback-1']['product_id'] );
	}

	/**
	 * Test that debug logging is disabled by default.
	 */
	public function test_debug_log_is_disabled_by_default() {
		$upload_dir = wp_upload_dir();
		$log_file = trailingslashit( (string) $upload_dir['basedir'] ) . 'miguel-logs/miguel-debug-' . gmdate( 'd-m-Y' ) . '.log';
		$marker = 'unit-test-debug-marker-' . wp_generate_uuid4();

		$size_before = file_exists( $log_file ) ? filesize( $log_file ) : 0;
		$content_before = file_exists( $log_file ) ? file_get_contents( $log_file ) : '';

		Miguel::debug_log( $marker, array( 'source' => 'unit-test' ) );

		clearstatcache();
		$size_after = file_exists( $log_file ) ? filesize( $log_file ) : 0;
		$content_after = file_exists( $log_file ) ? file_get_contents( $log_file ) : '';

		$this->assertSame( $size_before, $size_after );
		$this->assertSame( $content_before, $content_after );
		$this->assertStringNotContainsString( $marker, (string) $content_after );
	}
}
