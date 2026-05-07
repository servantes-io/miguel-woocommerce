<?php
/**
 * Test Miguel delivery methods API.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Delivery_Methods_Api extends Miguel_Test_Case {

	public function test_registers_rest_api_init_hook() {
		$hook_manager = $this->createMock( Miguel_Hook_Manager_Interface::class );
		$hook_manager->expects( $this->once() )
			->method( 'add_action' )
			->with(
				'rest_api_init',
				$this->isType( 'array' )
			);

		$api = new Miguel_Delivery_Methods_Api( $hook_manager );
		$api->register_hooks();
	}

	public function test_get_delivery_methods_returns_correct_structure_when_no_zones() {
		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'count', $data );
		$this->assertArrayHasKey( 'zones', $data );
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( array(), $data['zones'] );
	}

	public function test_get_delivery_methods_groups_methods_by_zone() {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['count'] );

		$found_zone = null;
		foreach ( $data['zones'] as $z ) {
			if ( $z['id'] === $zone->get_id() ) {
				$found_zone = $z;
				break;
			}
		}
		$this->assertNotNull( $found_zone, 'Expected zone not found in response' );
		$this->assertEquals( 'Test Zone', $found_zone['name'] );
		$this->assertArrayHasKey( 'methods', $found_zone );
		$this->assertCount( 1, $found_zone['methods'] );

		$method = $found_zone['methods'][0];
		$this->assertEquals( $instance_id, $method['instance_id'] );
		$this->assertEquals( 'flat_rate', $method['method_id'] );
		$this->assertArrayHasKey( 'title', $method );
		$this->assertArrayHasKey( 'enabled', $method );
		$this->assertArrayNotHasKey( 'zone_id', $method );
		$this->assertArrayNotHasKey( 'zone_name', $method );
	}
}
