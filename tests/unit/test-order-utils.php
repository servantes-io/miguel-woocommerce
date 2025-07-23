<?php
/**
 * Test Miguel Order Utils functionality
 *
 * @package Miguel\Tests
 */

class Test_Miguel_Order_Utils extends WC_Unit_Test_Case {

	/**
	 * Test user ID extraction for registered user
	 */
	public function test_get_user_id_for_order_registered_user() {
		// Create a user
		$user_id = wp_create_user( 'testuser', 'password123', 'test@example.com' );
		$user = get_user_by( 'id', $user_id );

		// Create order with user
		$order = Miguel_Helper_Order::create_order();
		$order->set_customer_id( $user_id );
		$order->save();

		$result = Miguel_Order_Utils::get_user_id_for_order( $order );
		$this->assertEquals( $user_id, $result );
	}

	/**
	 * Test user ID extraction for guest user
	 */
	public function test_get_user_id_for_order_guest_user() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_customer_id( 0 ); // Guest user
		$order->set_billing_email( 'guest@example.com' );
		$order->save();

		$result = Miguel_Order_Utils::get_user_id_for_order( $order );
		$expected = md5( 'guest@example.com' );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test email extraction
	 */
	public function test_get_email_for_order() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_billing_email( 'customer@example.com' );
		$order->save();

		$result = Miguel_Order_Utils::get_email_for_order( $order );
		$this->assertEquals( 'customer@example.com', $result );
	}

	/**
	 * Test full name formatting
	 */
	public function test_get_full_name_for_order() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_billing_first_name( 'John' );
		$order->set_billing_last_name( 'Doe' );
		$order->save();

		$result = Miguel_Order_Utils::get_full_name_for_order( $order );
		$this->assertEquals( 'John Doe', $result );
	}

	/**
	 * Test basic address formatting
	 */
	public function test_get_address_for_order_basic() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_billing_address_1( '123 Main St' );
		$order->set_billing_city( 'Springfield' );
		$order->save();

		$result = Miguel_Order_Utils::get_address_for_order( $order );
		$this->assertEquals( '123 Main St Springfield', $result );
	}

	/**
	 * Test enhanced address formatting
	 */
	public function test_get_address_for_order_enhanced() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_billing_address_1( '123 Main St' );
		$order->set_billing_city( 'Springfield' );
		$order->set_billing_postcode( '12345' );
		$order->set_billing_country( 'US' );
		$order->save();

		$result = Miguel_Order_Utils::get_address_for_order( $order, true );
		$this->assertStringContainsString( '123 Main St', $result );
		$this->assertStringContainsString( 'Springfield', $result );
		$this->assertStringContainsString( '12345', $result );
	}

	/**
	 * Test purchase date formatting
	 */
	public function test_get_purchase_date_for_order() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_date_paid( '2023-01-15 10:30:00' );
		$order->save();

		$result = Miguel_Order_Utils::get_purchase_date_for_order( $order );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '2023-01-15', $result );
	}

	/**
	 * Test complete user data array
	 */
	public function test_get_user_data_for_order() {
		$order = Miguel_Helper_Order::create_order();
		$order->set_billing_email( 'test@example.com' );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Smith' );
		$order->set_billing_address_1( '456 Oak Ave' );
		$order->set_billing_city( 'Metropolis' );
		$order->save();

		$result = Miguel_Order_Utils::get_user_data_for_order( $order );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'email', $result );
		$this->assertArrayHasKey( 'full_name', $result );
		$this->assertArrayHasKey( 'address', $result );
		$this->assertArrayHasKey( 'lang', $result );

		$this->assertEquals( 'test@example.com', $result['email'] );
		$this->assertEquals( 'Jane Smith', $result['full_name'] );
		$this->assertStringContainsString( '456 Oak Ave', $result['address'] );
	}

	/**
	 * Test shortcode attributes parsing
	 */
	public function test_parse_shortcode_atts() {
		// Test Miguel shortcodes
		$result = Miguel_Order_Utils::parse_shortcode_atts( '[miguel id="book-id" format="epub"]' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'book-id', $result['id'] );
		$this->assertEquals( 'epub', $result['format'] );

		$result = Miguel_Order_Utils::parse_shortcode_atts( '[miguel format="pdf" id="another-book"]' );
		$this->assertEquals( 'another-book', $result['id'] );
		$this->assertEquals( 'pdf', $result['format'] );

		// Test Wosa shortcodes (book attribute should be mapped to id)
		$result = Miguel_Order_Utils::parse_shortcode_atts( '[wosa book="wosa-book" format="mobi"]' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'wosa-book', $result['id'] );
		$this->assertEquals( 'wosa-book', $result['book'] );
		$this->assertEquals( 'mobi', $result['format'] );

		$result = Miguel_Order_Utils::parse_shortcode_atts( '[wosa format="epub" book="another-wosa"]' );
		$this->assertEquals( 'another-wosa', $result['id'] );
		$this->assertEquals( 'another-wosa', $result['book'] );
		$this->assertEquals( 'epub', $result['format'] );

		// Test invalid shortcodes
		$this->assertNull( Miguel_Order_Utils::parse_shortcode_atts( '[other shortcode]' ) );
		$this->assertNull( Miguel_Order_Utils::parse_shortcode_atts( 'http://example.com/file.pdf' ) );
		$this->assertNull( Miguel_Order_Utils::parse_shortcode_atts( '[miguelx id="test"]' ) );
		$this->assertNull( Miguel_Order_Utils::parse_shortcode_atts( '[wosax book="test"]' ) );

		// Test edge cases
		$result = Miguel_Order_Utils::parse_shortcode_atts( '[miguel id="book with spaces" format="pdf"]' );
		$this->assertEquals( 'book with spaces', $result['id'] );

		$result = Miguel_Order_Utils::parse_shortcode_atts( '[wosa book="book/with/slashes" format="epub"]' );
		$this->assertEquals( 'book/with/slashes', $result['id'] );
	}

	/**
	 * Test Miguel shortcode detection
	 */
	public function test_is_miguel_shortcode() {
		// Test Miguel shortcodes
		$this->assertTrue( Miguel_Order_Utils::is_miguel_shortcode( '[miguel id="book-id" format="epub"]' ) );
		$this->assertTrue( Miguel_Order_Utils::is_miguel_shortcode( '[miguel book="old-format" format="pdf"]' ) );

		// Test Wosa shortcodes (new functionality)
		$this->assertTrue( Miguel_Order_Utils::is_miguel_shortcode( '[wosa id="book-id" format="epub"]' ) );
		$this->assertTrue( Miguel_Order_Utils::is_miguel_shortcode( '[wosa book="old-format" format="pdf"]' ) );

		// Test non-matching strings
		$this->assertFalse( Miguel_Order_Utils::is_miguel_shortcode( 'http://example.com/file.pdf' ) );
		$this->assertFalse( Miguel_Order_Utils::is_miguel_shortcode( '[other shortcode]' ) );
		$this->assertFalse( Miguel_Order_Utils::is_miguel_shortcode( '[miguelx id="test"]' ) );
		$this->assertFalse( Miguel_Order_Utils::is_miguel_shortcode( '[wosax id="test"]' ) );
	}

	/**
	 * Test Miguel code extraction
	 */
	public function test_extract_miguel_code() {
		// Test current format with 'id' attribute from Miguel shortcodes
		$this->assertEquals( 'book-id', Miguel_Order_Utils::extract_miguel_code( '[miguel id="book-id" format="epub"]' ) );
		$this->assertEquals( 'another-book', Miguel_Order_Utils::extract_miguel_code( '[miguel format="pdf" id="another-book"]' ) );

		// Test without required attributes
		$this->assertNull( Miguel_Order_Utils::extract_miguel_code( '[miguel format="epub"]' ) );
		$this->assertNull( Miguel_Order_Utils::extract_miguel_code( '[wosa format="epub"]' ) );

		// Test non-Miguel/Wosa shortcodes
		$this->assertNull( Miguel_Order_Utils::extract_miguel_code( '[other shortcode]' ) );
		$this->assertNull( Miguel_Order_Utils::extract_miguel_code( 'http://example.com/file.pdf' ) );

		// Test edge cases
		$this->assertEquals( 'book-with-spaces', Miguel_Order_Utils::extract_miguel_code( '[miguel id="book-with-spaces" format="epub"]' ) );
		$this->assertEquals( 'book/with/slashes', Miguel_Order_Utils::extract_miguel_code( '[miguel id="book/with/slashes" format="pdf"]' ) );
		$this->assertEquals( 'wosa-with-spaces', Miguel_Order_Utils::extract_miguel_code( '[wosa book="wosa-with-spaces" format="epub"]' ) );
		$this->assertEquals( 'wosa/with/slashes', Miguel_Order_Utils::extract_miguel_code( '[wosa book="wosa/with/slashes" format="pdf"]' ) );
	}
}
