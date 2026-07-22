<?php
/**
 * Test Miguel_Products_Api miguel_items extraction.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Products_Api extends Miguel_Test_Case {

	private function find_product( $data, $product_id ) {
		foreach ( $data['products'] as $p ) {
			if ( (int) $p['id'] === (int) $product_id ) {
				return $p;
			}
		}
		return null;
	}

	public function test_digital_items_include_code_equal_to_book_id() {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$data  = ( new Miguel_Products_Api( new Miguel_Hook_Manager() ) )->get_products()->get_data();
		$found = $this->find_product( $data, $product->get_id() );

		$this->assertNotNull( $found );
		$this->assertNotEmpty( $found['miguel_items'] );
		foreach ( $found['miguel_items'] as $item ) {
			$this->assertArrayHasKey( 'code', $item );
			$this->assertSame( 'dummy-name', $item['book_id'] );
			$this->assertSame( $item['book_id'], $item['code'] );
		}
	}

	public function test_physical_product_yields_print_item_with_code() {
		add_filter( 'miguel_print_code_suffix', function () {
			return ':print';
		} );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_downloadable( false );
		$product->set_virtual( false );
		$product->set_sku( 'printed-1' );
		$product->save();

		$data  = ( new Miguel_Products_Api( new Miguel_Hook_Manager() ) )->get_products()->get_data();
		$found = $this->find_product( $data, $product->get_id() );

		$this->assertNotNull( $found );
		$this->assertCount( 1, $found['miguel_items'] );
		$this->assertSame( 'printed-1:print', $found['miguel_items'][0]['code'] );
		$this->assertSame( 'printed-1', $found['miguel_items'][0]['book_id'] );
		$this->assertSame( 'print', $found['miguel_items'][0]['format'] );
	}
}
