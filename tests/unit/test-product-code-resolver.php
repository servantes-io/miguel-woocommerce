<?php
/**
 * Test Miguel product code resolver.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Code_Resolver extends Miguel_Test_Case {

	/**
	 * Shortcode products map by their shortcode id; a downloadable product with
	 * no shortcode falls back to its bare SKU (resolver-only fallback).
	 */
	public function test_maps_shortcode_and_digital_sku_fallback() {
		$shortcode_product = Miguel_Helper_Product::create_downloadable_product();
		$shortcode_product->set_sku( 'shortcode-product-sku' ); // unique SKU, irrelevant (shortcode wins)
		$shortcode_product->save();

		$sku_product = WC_Helper_Product::create_simple_product();
		$sku_product->set_virtual( true );
		$sku_product->set_downloadable( true );
		$sku_product->set_sku( 'sku-fallback-1' );
		$sku_product->save();

		$details_map = ( new Miguel_Product_Code_Resolver() )->get_product_code_details_map();

		$this->assertArrayHasKey( 'dummy-name', $details_map );
		$this->assertEquals( $shortcode_product->get_id(), $details_map['dummy-name']['product_id'] );
		$this->assertArrayHasKey( 'sku-fallback-1', $details_map );
		$this->assertEquals( $sku_product->get_id(), $details_map['sku-fallback-1']['product_id'] );
	}

	/**
	 * An e-book and a printed book that share a slug resolve to distinct products.
	 */
	public function test_ebook_and_print_sharing_slug_resolve_to_distinct_products() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		// E-book: downloadable, shortcode id "harry-potter". Give it a unique SKU first.
		$ebook = Miguel_Helper_Product::create_downloadable_product();
		$ebook->set_sku( 'harry-potter-ebook-sku' );
		$ebook->save();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$ebook,
			array(
				'hp_epub_' . wp_generate_uuid4() => array(
					'name' => 'HP epub',
					'file' => '[miguel id="harry-potter" format="epub"]',
				),
			)
		);

		// Printed book: non-downloadable, SKU "harry-potter".
		$print = WC_Helper_Product::create_simple_product();
		$print->set_downloadable( false );
		$print->set_virtual( false );
		$print->set_sku( 'harry-potter' );
		$print->save();

		$resolver  = new Miguel_Product_Code_Resolver();
		$ebook_res = $resolver->resolve_product_code( 'harry-potter' );
		$print_res = $resolver->resolve_product_code( 'harry-potter:print' );

		$this->assertSame( $ebook->get_id(), $ebook_res['product_id'] );
		$this->assertTrue( $ebook_res['is_unique'] );
		$this->assertSame( $print->get_id(), $print_res['product_id'] );
		$this->assertTrue( $print_res['is_unique'] );
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
