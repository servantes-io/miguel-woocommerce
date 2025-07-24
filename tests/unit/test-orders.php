<?php
/**
 * Test Miguel Orders functionality
 *
 * @package Miguel\Tests
 */

class Test_Miguel_Orders extends WC_Unit_Test_Case {

	/**
	 * Test order synchronization detection
	 */
	public function test_has_miguel_products() {
		// Create a downloadable product with Miguel shortcode
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->save();

		// Create orders instance
		$sut = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $sut );
		$method = $reflection->getMethod( 'has_miguel_products' );
		$method->setAccessible( true );

		$result = $method->invoke( $sut, $order );

		$this->assertTrue( $result, 'Order should contain Miguel products' );
	}



	/**
	 * Test order data preparation
	 */
	public function test_prepare_order_data() {
		// Create a downloadable product with Miguel shortcode
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$sut = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $sut );
		$method = $reflection->getMethod( 'prepare_order_data' );
		$method->setAccessible( true );

		$result = $method->invoke( $sut, $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertArrayHasKey( 'user', $result );
		$this->assertArrayHasKey( 'products', $result );
		$this->assertArrayHasKey( 'currency_code', $result );
		$this->assertArrayHasKey( 'purchase_date', $result );

		// Status and total_price were removed by user
		$this->assertArrayNotHasKey( 'status', $result );
		$this->assertArrayNotHasKey( 'total_price', $result );

		$this->assertEquals( strval( $order->get_id() ), $result['code'] );
		$this->assertIsArray( $result['products'] );
		$this->assertNotEmpty( $result['products'] );

		// Check product data
		$product_data = $result['products'][0];
		$this->assertArrayHasKey( 'code', $product_data );
		$this->assertEquals( 'dummy-name', $product_data['code'] );
		$this->assertArrayHasKey( 'price', $product_data );
		$this->assertEquals( 10.00, $product_data['price']['sold_without_vat'] );

		// Check user data structure
		$user_data = $result['user'];
		$this->assertArrayHasKey( 'id', $user_data );
		$this->assertArrayHasKey( 'email', $user_data );
		$this->assertArrayHasKey( 'full_name', $user_data );
		$this->assertArrayHasKey( 'address', $user_data );
		$this->assertArrayHasKey( 'lang', $user_data );
	}

	/**
	 * Test that multiple unique codes create separate product items
	 */
	public function test_prepare_order_data_multiple_codes() {
		// Create a downloadable product with multiple unique Miguel codes
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Set downloads directly via meta data to bypass WooCommerce validation
		$downloads = array(
			'book1_epub_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="epub"]',
			),
			'book1_pdf_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="pdf"]',
			),
			'book1_mobi_' . wp_generate_uuid4() => array(
				'name' => 'Book 1',
				'file' => '[miguel id="book-1" format="mobi"]',
			),
		);

		// Use helper method to set downloads bypassing validation
		Miguel_Helper_Product::set_product_downloads_bypass_validation( $product, $downloads );

		// Create a downloadable product with multiple unique Miguel codes
		$product2 = Miguel_Helper_Product::create_downloadable_product();

		// Set downloads directly via meta data to bypass WooCommerce validation
		$downloads2 = array(
			'book2_epub_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="epub"]',
			),
			'book2_pdf_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="pdf"]',
			),
			'book2_mobi_' . wp_generate_uuid4() => array(
				'name' => 'Book 2',
				'file' => '[miguel id="book-2" format="mobi"]',
			),
		);

		// Use helper method to set downloads bypassing validation
		Miguel_Helper_Product::set_product_downloads_bypass_validation( $product2, $downloads2 );

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->add_product( $product2, 1 );
		$order->save();

		$sut = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $sut );
		$method = $reflection->getMethod( 'prepare_order_data' );
		$method->setAccessible( true );

		$result = $method->invoke( $sut, $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'products', $result );

		// Should have 4 separate product items, one for each unique code
		$this->assertCount( 2, $result['products'] );

		// Check that each product item has a string code (not array)
		$codes_found = array();
		foreach ( $result['products'] as $product_data ) {
			$this->assertArrayHasKey( 'code', $product_data );
			$this->assertIsString( $product_data['code'] );
			$this->assertArrayHasKey( 'price', $product_data );
			$this->assertArrayHasKey( 'sold_without_vat', $product_data['price'] );

			$codes_found[] = $product_data['code'];
		}

		// Verify we have all expected codes
		$expected_codes = array( 'book-1', 'book-2' );
		sort( $codes_found );
		sort( $expected_codes );
		$this->assertEquals( $expected_codes, $codes_found );
	}
}
