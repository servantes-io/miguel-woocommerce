<?php
/**
 * Tests for Miguel_Order_Mapper.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Order_Mapper extends Miguel_Test_Case {

	public function test_maps_single_product_with_quantity_and_address(): void {
		$product = Miguel_Helper_Product::create_downloadable_product();

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 2 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();
		$dto    = $mapper->map( $order );

		$this->assertInstanceOf( Miguel_V2_Order_Create::class, $dto );

		$arr = $dto->to_array();
		$this->assertSame( strval( $order->get_id() ), $arr['code'] );
		$this->assertSame( strval( $order->get_id() ), $arr['eshopId'] );
		$this->assertSame( 'disable', $arr['sendEmail'] );
		$this->assertSame( $order->get_currency(), $arr['currencyCode'] );
		$this->assertNull( $arr['source'] );
		$this->assertNull( $arr['socialDrmContent'] );

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( 'dummy-name', $arr['items'][0]['code'] );
		$this->assertSame( 10.0, $arr['items'][0]['soldPrice'] );
		$this->assertSame( 2, $arr['items'][0]['quantity'] );

		// Guest order => null user id.
		$this->assertNull( $arr['user']['id'] );
		$this->assertSame( $order->get_billing_email(), $arr['user']['email'] );

		// Billing address present (helper sets billing fields).
		$this->assertArrayHasKey( 'billingAddress', $arr );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_returns_null_without_miguel_items(): void {
		// Build a non-Miguel downloadable product (no Miguel shortcode in its
		// downloads) inline, because Miguel_Helper_Product::create_simple_product()
		// does not exist.
		$product = WC_Helper_Product::create_simple_product();
		$product->set_virtual( 'yes' );
		$product->set_downloadable( 'yes' );
		$product->save();

		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$product,
			array(
				'plain_file_' . wp_generate_uuid4() => array(
					'name' => 'Plain download',
					'file' => 'https://example.com/file.pdf',
				),
			)
		);

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();
		$this->assertNull( $mapper->map( $order ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
