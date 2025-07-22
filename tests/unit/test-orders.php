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
		$orders = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $orders );
		$method = $reflection->getMethod( 'has_miguel_products' );
		$method->setAccessible( true );

		$result = $method->invoke( $orders, $order );

		$this->assertTrue( $result, 'Order should contain Miguel products' );
	}

	/**
	 * Test Miguel shortcode detection
	 */
	public function test_is_miguel_shortcode() {
		$orders = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $orders );
		$method = $reflection->getMethod( 'is_miguel_shortcode' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $orders, '[miguel id="book-id" format="epub"]' ) );
		$this->assertTrue( $method->invoke( $orders, '[miguel book="old-format" format="pdf"]' ) );
		$this->assertFalse( $method->invoke( $orders, 'http://example.com/file.pdf' ) );
		$this->assertFalse( $method->invoke( $orders, '[other shortcode]' ) );
	}

	/**
	 * Test Miguel code extraction
	 */
	public function test_extract_miguel_code() {
		$orders = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $orders );
		$method = $reflection->getMethod( 'extract_miguel_code' );
		$method->setAccessible( true );

		// Test current format with 'id' attribute
		$this->assertEquals( 'book-id', $method->invoke( $orders, '[miguel id="book-id" format="epub"]' ) );
		$this->assertEquals( 'another-book', $method->invoke( $orders, '[miguel format="pdf" id="another-book"]' ) );

		// Test legacy format with 'book' attribute
		$this->assertEquals( 'old-book', $method->invoke( $orders, '[miguel book="old-book" format="mobi"]' ) );

		// Test without required attributes
		$this->assertNull( $method->invoke( $orders, '[miguel format="epub"]' ) );

		// Test non-Miguel shortcodes
		$this->assertNull( $method->invoke( $orders, '[other shortcode]' ) );
		$this->assertNull( $method->invoke( $orders, 'http://example.com/file.pdf' ) );

		// Test edge cases
		$this->assertEquals( 'book-with-spaces', $method->invoke( $orders, '[miguel id="book-with-spaces" format="epub"]' ) );
		$this->assertEquals( 'book/with/slashes', $method->invoke( $orders, '[miguel id="book/with/slashes" format="pdf"]' ) );
	}

	/**
	 * Test order data preparation
	 */
	public function test_prepare_order_data() {
		// Create a downloadable product with Miguel shortcode
		$product = Miguel_Helper_Product::create_downloadable_product();

		// Create order with the product
		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 2 ); // quantity 2
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$orders = new Miguel_Orders();

		// Use reflection to access private method
		$reflection = new ReflectionClass( $orders );
		$method = $reflection->getMethod( 'prepare_order_data' );
		$method->setAccessible( true );

		$result = $method->invoke( $orders, $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result ); // Changed from 'order_code'
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
		$this->assertArrayHasKey( 'codes', $product_data );
		$this->assertArrayHasKey( 'quantity', $product_data );
		$this->assertArrayHasKey( 'unit_price', $product_data );
		$this->assertEquals( 2, $product_data['quantity'] );

		// Check user data structure
		$user_data = $result['user'];
		$this->assertArrayHasKey( 'id', $user_data );
		$this->assertArrayHasKey( 'email', $user_data );
		$this->assertArrayHasKey( 'full_name', $user_data );
		$this->assertArrayHasKey( 'address', $user_data );
		$this->assertArrayHasKey( 'lang', $user_data );
	}
}
