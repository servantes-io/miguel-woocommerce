<?php
/**
 * Tests for Miguel_Watermark_Mapper.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Watermark_Mapper extends Miguel_Test_Case {

	public function test_maps_order_item_and_file(): void {
		$order = Miguel_Helper_Order::create_order();
		$item  = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$req    = $mapper->map( $order, $item, $file );

		$this->assertInstanceOf( Miguel_V2_Watermarked_File_Request::class, $req );

		$arr = $req->to_array();
		$this->assertSame( 'epub', $arr['target'] );
		$this->assertSame( strval( $order->get_id() ), $arr['orderInfo']['code'] );
		$this->assertSame( $order->get_currency(), $arr['orderInfo']['currencyCode'] );
		$this->assertSame( 10.0, $arr['orderInfo']['soldPrice'] );
		$this->assertSame( $order->get_billing_email(), $arr['userInfo']['email'] );
		$this->assertSame( $order->get_date_paid()->format( DateTime::ATOM ), $arr['purchaseDate'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_maps_permission_of_a_deleted_order(): void {
		$user_id    = self::factory()->user->create( array( 'locale' => 'cs_CZ' ) );
		$permission = new WC_Customer_Download();
		$permission->set_order_id( 4242 );
		$permission->set_user_id( $user_id );
		$permission->set_user_email( 'buyer@example.com' );
		$permission->set_access_granted( '2019-05-01' );

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'mobi' );

		$mapper = new Miguel_Watermark_Mapper();
		$arr    = $mapper->map_permission( $permission, $file )->to_array();

		$this->assertSame( 'mobi', $arr['target'] );
		$this->assertSame(
			array(
				'id'       => strval( $user_id ),
				'name'     => null,
				'address'  => null,
				'email'    => 'buyer@example.com',
				'language' => 'cs_CZ',
			),
			$arr['userInfo']
		);
		$this->assertSame( $permission->get_access_granted()->format( DateTime::ATOM ), $arr['purchaseDate'] );
		$this->assertSame(
			array(
				'code'         => '4242',
				'currencyCode' => get_woocommerce_currency(),
				'soldPrice'    => 0.0,
			),
			$arr['orderInfo']
		);
	}

	public function test_returns_null_when_not_paid(): void {
		$order = Miguel_Helper_Order::create_order();
		$order->set_status( 'pending' );
		$order->set_date_paid( null );
		$order->save();
		$item = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$this->assertNull( $mapper->map( $order, $item, $file ) );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_paid_order_without_date_paid_falls_back_to_date_created(): void {
		$order = Miguel_Helper_Order::create_order();
		$order->set_date_created( '2020-03-14 10:00:00' );
		$order->set_date_paid( null );
		$order->save();
		$item = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$req    = $mapper->map( $order, $item, $file );

		$this->assertInstanceOf( Miguel_V2_Watermarked_File_Request::class, $req );
		$this->assertSame( $order->get_date_created()->format( DateTime::ATOM ), $req->to_array()['purchaseDate'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}

	public function test_paid_order_without_date_paid_prefers_date_completed(): void {
		$order = Miguel_Helper_Order::create_order();
		$order->set_date_created( '2020-03-14 10:00:00' );
		$order->set_date_completed( '2020-03-15 12:00:00' );
		$order->set_date_paid( null );
		$order->save();
		$item = array_values( $order->get_items() )[0];

		$file = $this->getMockBuilder( Miguel_File::class )
			->disableOriginalConstructor()
			->getMock();
		$file->method( 'get_format' )->willReturn( 'epub' );

		$mapper = new Miguel_Watermark_Mapper();
		$req    = $mapper->map( $order, $item, $file );

		$this->assertInstanceOf( Miguel_V2_Watermarked_File_Request::class, $req );
		$this->assertSame( $order->get_date_completed()->format( DateTime::ATOM ), $req->to_array()['purchaseDate'] );

		Miguel_Helper_Order::delete_order( $order->get_id() );
	}
}
