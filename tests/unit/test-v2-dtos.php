<?php
/**
 * Tests for v2 DTO value objects.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_V2_Dtos extends WP_UnitTestCase {

	public function test_watermark_user_full(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John Doe', 'Main St 1, Prague' );

		$this->assertEquals(
			array(
				'id'       => '42',
				'name'     => 'John Doe',
				'address'  => 'Main St 1, Prague',
				'email'    => 'a@b.cz',
				'language' => 'cs_CZ',
			),
			$user->to_array()
		);
	}

	public function test_watermark_user_guest_has_null_optionals(): void {
		$user = new Miguel_V2_Watermark_User( 'guest@b.cz', 'en_US' );

		$arr = $user->to_array();
		$this->assertNull( $arr['id'] );
		$this->assertNull( $arr['name'] );
		$this->assertNull( $arr['address'] );
		$this->assertSame( 'guest@b.cz', $arr['email'] );
		$this->assertSame( 'en_US', $arr['language'] );
	}

	public function test_order_address_maps_known_keys(): void {
		$address = new Miguel_V2_Order_Address(
			array(
				'fullName' => 'John Doe',
				'company'  => 'Acme',
				'address1' => 'Main St 1',
				'address2' => null,
				'city'     => 'Prague',
				'state'    => '',
				'zip'      => '11000',
				'country'  => 'CZ',
				'phone'    => '+420123',
			)
		);

		$this->assertEquals(
			array(
				'fullName' => 'John Doe',
				'company'  => 'Acme',
				'address1' => 'Main St 1',
				'address2' => null,
				'city'     => 'Prague',
				'state'    => null,
				'zip'      => '11000',
				'country'  => 'CZ',
				'phone'    => '+420123',
			),
			$address->to_array()
		);
	}

	public function test_order_create_item_without_delivery_method(): void {
		$item = new Miguel_V2_Order_Create_Item( 'book-1', 10.0, 2 );

		$this->assertEquals(
			array(
				'code'      => 'book-1',
				'soldPrice' => 10.0,
				'quantity'  => 2,
			),
			$item->to_array()
		);
	}

	public function test_order_create_item_with_delivery_method(): void {
		$item = new Miguel_V2_Order_Create_Item( 'book-1', 9.5, 1, 7 );

		$arr = $item->to_array();
		$this->assertSame( 7, $arr['deliveryMethodId'] );
		$this->assertSame( 9.5, $arr['soldPrice'] );
		$this->assertSame( 1, $arr['quantity'] );
	}

	public function test_order_create_serializes_nested_and_omits_empty_addresses(): void {
		$user  = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John', 'Main St' );
		$items = array( new Miguel_V2_Order_Create_Item( 'book-1', 10.0, 1 ) );

		$order = new Miguel_V2_Order_Create(
			'1001',                       // code
			$user,
			'2023-01-15T10:00:00+00:00',  // purchasedAt
			'CZK',                        // currencyCode
			$items,
			'disable',                    // sendEmail
			'1001',                       // eshopId
			'2023-01-14T09:00:00+00:00',  // eshopCreatedAt
			'2023-01-15T10:00:00+00:00',  // eshopUpdatedAt
			null,                         // source
			null,                         // socialDrmContent
			null,                         // billingAddress
			null                          // shippingAddress
		);

		$arr = $order->to_array();

		$this->assertSame( '1001', $arr['code'] );
		$this->assertSame( 'disable', $arr['sendEmail'] );
		$this->assertSame( 'CZK', $arr['currencyCode'] );
		$this->assertNull( $arr['source'] );
		$this->assertNull( $arr['socialDrmContent'] );
		$this->assertSame( $user->to_array(), $arr['user'] );
		$this->assertSame( array( $items[0]->to_array() ), $arr['items'] );
		$this->assertArrayNotHasKey( 'billingAddress', $arr );
		$this->assertArrayNotHasKey( 'shippingAddress', $arr );
	}

	public function test_order_create_includes_non_empty_addresses(): void {
		$user    = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ' );
		$billing = new Miguel_V2_Order_Address( array( 'city' => 'Prague' ) );

		$order = new Miguel_V2_Order_Create(
			'1', $user, null, 'CZK', array(), 'disable', '1', null, null, null, null, $billing, null
		);

		$arr = $order->to_array();
		$this->assertSame( $billing->to_array(), $arr['billingAddress'] );
		$this->assertArrayNotHasKey( 'shippingAddress', $arr );
	}

	public function test_watermarked_file_request(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John', 'Main St' );

		$req = new Miguel_V2_Watermarked_File_Request(
			'epub',
			$user,
			'2023-01-15T10:00:00+00:00',
			'1001',
			'CZK',
			10.0
		);

		$this->assertEquals(
			array(
				'target'      => 'epub',
				'userInfo'    => $user->to_array(),
				'purchaseDate' => '2023-01-15T10:00:00+00:00',
				'orderInfo'   => array(
					'code'         => '1001',
					'currencyCode' => 'CZK',
					'soldPrice'    => 10.0,
				),
			),
			$req->to_array()
		);
	}
}
