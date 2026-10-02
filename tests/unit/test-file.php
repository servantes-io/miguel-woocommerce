<?php
/**
 * Test Miguel_File
 *
 * @package Miguel\Tests
 */
class Miguel_Test_File extends Miguel_Test_Case {

	/**
	 * WooCommerce download IDs as they occur: the helper's own key, and UUIDs as WooCommerce generates them,
	 * starting with a letter and with a digit.
	 */
	public function data_provider_download_ids() {
		return array(
			'helper key'           => array( null ),
			'UUID, leading letter' => array( 'a6442a73-b4f3-463b-a4b7-d0a8166ada29' ),
			'UUID, leading digit'  => array( '5a7841a5-cebc-4007-9ae9-0aeb15e53fd1' ),
		);
	}

	/**
	 * A download is served from the file built here, so it must accept WooCommerce's string download IDs.
	 *
	 * @dataProvider data_provider_download_ids
	 *
	 * @param string|null $download_id Download ID to store, or null for the one the helper generates.
	 */
	public function test_miguel_get_file_accepts_woocommerce_download_id( $download_id ): void {
		$product = Miguel_Helper_Product::create_miguel_product( 'restart' );
		if ( null !== $download_id ) {
			Miguel_Helper_Product::set_product_downloads_bypass_validation(
				$product,
				array(
					$download_id => array(
						'name' => 'Book restart',
						'file' => '[miguel id="restart" format="epub"]',
					),
				)
			);
			$product = wc_get_product( $product->get_id() );
		}
		$download_id = array_key_first( $product->get_downloads() );

		$file = miguel_get_file( $product->get_id(), $download_id );

		$this->assertInstanceOf( Miguel_File::class, $file );
		$this->assertTrue( $file->is_valid() );
		$this->assertSame( 'restart', $file->get_name() );
		$this->assertSame( 'epub', $file->get_format() );
		$this->assertSame( $download_id, $file->get_download_id() );
	}
}
