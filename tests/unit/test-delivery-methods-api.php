<?php
/**
 * Test Miguel delivery methods API.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Delivery_Methods_Api extends Miguel_Test_Case {

	public function test_routes_are_registered() {
		$api = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );

		add_action( 'rest_api_init', array( $api, 'register_routes' ) );
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'miguel/v1' );

		$this->assertArrayHasKey( '/miguel/v1/delivery-methods', $routes );
	}

	public function test_get_delivery_methods_returns_correct_structure_when_no_zones() {
		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'count', $data );
		$this->assertArrayHasKey( 'methods', $data );
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( array(), $data['methods'] );
	}

	public function test_get_delivery_methods_returns_methods_from_zone() {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThanOrEqual( 1, $data['count'] );

		$found = false;
		foreach ( $data['methods'] as $method ) {
			if ( $method['instance_id'] === $instance_id ) {
				$found = true;
				$this->assertEquals( 'flat_rate', $method['method_id'] );
				$this->assertEquals( 'Test Zone', $method['zone_name'] );
				$this->assertEquals( $zone->get_id(), $method['zone_id'] );
				$this->assertArrayHasKey( 'title', $method );
				$this->assertArrayHasKey( 'enabled', $method );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected method not found in response' );
	}
}
