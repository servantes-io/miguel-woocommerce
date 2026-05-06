<?php
/**
 * Test Miguel product code map API.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Code_Map_Api extends Miguel_Test_Case {

	/**
	 * Test that hooks are registered correctly.
	 */
	public function test_registers_correct_hooks() {
		$hook_manager_mock = $this->createMock( Miguel_Hook_Manager_Interface::class );

		$hook_manager_mock->expects( $this->once() )
			->method( 'add_action' )
			->with( 'rest_api_init', $this->anything(), 10, 1 );

		$api = new Miguel_Product_Code_Map_Api( $hook_manager_mock );
		$api->register_hooks();
	}

	/**
	 * Test that product codes are mapped to WooCommerce product IDs.
	 */
	public function test_get_product_code_map_returns_product_ids_for_codes() {
		$product_one = Miguel_Helper_Product::create_downloadable_product();

		$product_two = Miguel_Helper_Product::create_downloadable_product();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$product_two,
			array(
				'book_2_epub_' . wp_generate_uuid4() => array(
					'name' => 'Book 2',
					'file' => '[miguel id="book-2" format="epub"]',
				),
				'book_2_pdf_' . wp_generate_uuid4() => array(
					'name' => 'Book 2 PDF',
					'file' => '[miguel id="book-2" format="pdf"]',
				),
			)
		);

		$product_three = Miguel_Helper_Product::create_downloadable_product();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$product_three,
			array(
				'book_2_audio_' . wp_generate_uuid4() => array(
					'name' => 'Book 2 Audio',
					'file' => '[audio id="book-2" format="audio"]',
				),
			)
		);

		$api = new Miguel_Product_Code_Map_Api( new Miguel_Hook_Manager() );
		$response = $api->get_product_code_map();
		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'count', $data );
		$this->assertArrayHasKey( 'duplicate_count', $data );
		$this->assertArrayHasKey( 'product_code_map', $data );
		$this->assertArrayHasKey( 'product_code_details', $data );
		$this->assertEquals( 2, $data['count'] );
		$this->assertEquals( 1, $data['duplicate_count'] );
		$this->assertEquals( $product_one->get_id(), $data['product_code_map']['dummy-name'] );
		$this->assertEquals( $product_two->get_id(), $data['product_code_map']['book-2'] );
		$this->assertTrue( $data['product_code_details']['dummy-name']['is_unique'] );
		$this->assertFalse( $data['product_code_details']['book-2']['is_unique'] );
		$this->assertEquals( 2, $data['product_code_details']['book-2']['match_count'] );
		$this->assertEquals(
			array( $product_two->get_id(), $product_three->get_id() ),
			$data['product_code_details']['book-2']['product_ids']
		);
	}
}

