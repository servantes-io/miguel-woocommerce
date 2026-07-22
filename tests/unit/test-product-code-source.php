<?php
/**
 * Test Miguel_Product_Code_Source.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Code_Source extends Miguel_Test_Case {

	public function test_digital_shortcode_product_exposes_book_id_format_and_code() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$source  = new Miguel_Product_Code_Source();

		$items   = $source->get_items( $product );
		$formats = array_column( $items, 'format' );

		$this->assertNotEmpty( $items );
		foreach ( $items as $item ) {
			$this->assertSame( 'dummy-name', $item['book_id'] );
			$this->assertSame( 'digital', $item['type'] );
			$this->assertSame( $item['book_id'], $item['code'] ); // code == book_id for digital
		}
		$this->assertContains( 'epub', $formats );
		$this->assertContains( 'mobi', $formats );
	}

	public function test_get_codes_deduplicates_multi_format_shortcodes() {
		$product = Miguel_Helper_Product::create_downloadable_product();
		$source  = new Miguel_Product_Code_Source();

		$this->assertSame( array( 'dummy-name' ), $source->get_codes( $product ) );
	}

	public function test_physical_product_exposes_suffixed_print_code() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'harry-potter' );
		$product->save();

		$items = ( new Miguel_Product_Code_Source() )->get_items( $product );

		$this->assertCount( 1, $items );
		$this->assertSame( 'harry-potter:print', $items[0]['code'] );
		$this->assertSame( 'harry-potter', $items[0]['book_id'] );
		$this->assertSame( 'print', $items[0]['format'] );
		$this->assertSame( 'print', $items[0]['type'] );
	}

	public function test_physical_product_exposes_nothing_when_suffix_empty() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_sku( 'harry-potter' );
		$product->save();

		$this->assertSame( array(), ( new Miguel_Product_Code_Source() )->get_codes( $product ) );
	}

	public function test_override_meta_is_used_verbatim() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_sku( 'harry-potter' );
		$product->save();
		update_post_meta( $product->get_id(), '_miguel_code', 'custom-code-123' );

		$items = ( new Miguel_Product_Code_Source() )->get_items( $product );

		$this->assertCount( 1, $items );
		$this->assertSame( 'custom-code-123', $items[0]['code'] ); // no suffix appended
		$this->assertSame( 'custom-code-123', $items[0]['book_id'] );
		$this->assertSame( 'print', $items[0]['type'] ); // non-downloadable override => print
	}

	public function test_digital_sku_fallback_only_when_enabled() {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->set_sku( 'ebook-by-sku' );
		$product->save();

		$source = new Miguel_Product_Code_Source();

		$this->assertSame( array(), $source->get_codes( $product ) );                   // default: no fallback
		$this->assertSame( array( 'ebook-by-sku' ), $source->get_codes( $product, true ) ); // resolver mode
	}

	public function test_print_suffix_is_trimmed() {
		add_filter( 'miguel_print_code_suffix', function () {
			return '  :print  ';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'trim-me' );
		$product->save();

		$items = ( new Miguel_Product_Code_Source() )->get_items( $product );

		$this->assertCount( 1, $items );
		$this->assertSame( 'trim-me:print', $items[0]['code'] );
	}

	public function test_non_downloadable_without_sku_exposes_nothing() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_sku( '' );
		$product->save();

		$this->assertSame( array(), ( new Miguel_Product_Code_Source() )->get_codes( $product ) );
	}
}
