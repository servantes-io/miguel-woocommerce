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
		$this->assertSame( $arr['source'], 'woocommerce' );
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

	public function test_maps_bundle_with_proportional_sold_prices(): void {
		// Two downloadable Miguel products with distinct codes and regular prices.
		$book1 = Miguel_Helper_Product::create_downloadable_product();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$book1,
			array(
				'bundle_book1_epub_' . wp_generate_uuid4() => array(
					'name' => 'Bundle book 1',
					'file' => '[miguel id="bundle-book-1" format="epub"]',
				),
			)
		);
		$book1->set_regular_price( '30' );
		$book1->save();

		$book2 = Miguel_Helper_Product::create_downloadable_product();
		Miguel_Helper_Product::set_product_downloads_bypass_validation(
			$book2,
			array(
				'bundle_book2_epub_' . wp_generate_uuid4() => array(
					'name' => 'Bundle book 2',
					'file' => '[miguel id="bundle-book-2" format="epub"]',
				),
			)
		);
		$book2->set_regular_price( '10' );
		$book2->save();

		// Parent "bundle" product referencing the two books via _bundle_ids meta.
		// It is not itself downloadable, so only the bundled books yield codes.
		$bundle = WC_Helper_Product::create_simple_product();
		$bundle->set_regular_price( '20' );
		$bundle->save();
		update_post_meta(
			$bundle->get_id(),
			'_bundle_ids',
			array(
				(string) $book1->get_id() => array(),
				(string) $book2->get_id() => array(),
			)
		);

		$order = Miguel_Helper_Order::create_order();
		$order->add_product( $bundle, 1 );
		$order->set_status( 'processing' );
		$order->set_date_paid( '2023-01-15 10:00:00' );
		$order->save();

		$mapper = new Miguel_Order_Mapper();
		$dto    = $mapper->map( $order );

		$this->assertInstanceOf( Miguel_V2_Order_Create::class, $dto );

		$arr = $dto->to_array();
		$this->assertCount( 2, $arr['items'] );

		$by_code = array();
		foreach ( $arr['items'] as $item ) {
			$by_code[ $item['code'] ] = $item;
			$this->assertSame( 1, $item['quantity'] );
		}

		$this->assertArrayHasKey( 'bundle-book-1', $by_code );
		$this->assertArrayHasKey( 'bundle-book-2', $by_code );

		// Bundle per-unit line total is 20; split proportionally to 30:10.
		$this->assertSame( 15.0, $by_code['bundle-book-1']['soldPrice'] );
		$this->assertSame( 5.0, $by_code['bundle-book-2']['soldPrice'] );

		// Proportional split sums back to the line total.
		$this->assertSame(
			20.0,
			$by_code['bundle-book-1']['soldPrice'] + $by_code['bundle-book-2']['soldPrice']
		);

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
