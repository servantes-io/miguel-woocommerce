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
		$this->assertArrayHasKey( 'locations', $found_zone );
		$this->assertIsArray( $found_zone['locations'] );
		$this->assertArrayHasKey( 'methods', $found_zone );
		$this->assertCount( 1, $found_zone['methods'] );

		$method = $found_zone['methods'][0];
		$this->assertEquals( $instance_id, $method['instance_id'] );
		$this->assertEquals( 'flat_rate', $method['method_id'] );
		$this->assertArrayHasKey( 'title', $method );
		$this->assertArrayHasKey( 'description', $method );
		$this->assertArrayHasKey( 'enabled', $method );
		$this->assertArrayHasKey( 'cost', $method );
		$this->assertArrayHasKey( 'min_amount', $method );
		$this->assertArrayHasKey( 'free_shipping', $method );
		$this->assertArrayHasKey( 'requires', $method );
		$this->assertArrayHasKey( 'ignore_discounts', $method );
		$this->assertArrayNotHasKey( 'zone_id', $method );
		$this->assertArrayNotHasKey( 'zone_name', $method );
	}

	public function test_zone_includes_locations_with_correct_structure() {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Europe Zone' );
		$zone->save();
		$zone->add_location( 'CZ', 'country' );
		$zone->add_location( 'SK', 'country' );
		$zone->save();
		$zone->add_shipping_method( 'flat_rate' );

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );
		$data     = $response->get_data();

		$found_zone = null;
		foreach ( $data['zones'] as $z ) {
			if ( $z['id'] === $zone->get_id() ) {
				$found_zone = $z;
				break;
			}
		}
		$this->assertNotNull( $found_zone, 'Expected zone not found in response' );
		$this->assertCount( 2, $found_zone['locations'] );

		$codes = array_column( $found_zone['locations'], 'code' );
		$this->assertContains( 'CZ', $codes );
		$this->assertContains( 'SK', $codes );

		foreach ( $found_zone['locations'] as $loc ) {
			$this->assertArrayHasKey( 'type', $loc );
			$this->assertArrayHasKey( 'code', $loc );
		}
	}

	public function test_free_shipping_method_includes_requires_and_ignore_discounts() {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Free Shipping Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'free_shipping' );

		// Configure the free_shipping instance with a min_amount and requires condition.
		$option_key = 'woocommerce_free_shipping_' . $instance_id . '_settings';
		update_option(
			$option_key,
			array(
				'title'            => 'Free Shipping',
				'requires'         => 'min_amount',
				'min_amount'       => '50',
				'ignore_discounts' => 'yes',
			)
		);

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );

		$response = $api->get_delivery_methods( $request );
		$data     = $response->get_data();

		$found_zone = null;
		foreach ( $data['zones'] as $z ) {
			if ( $z['id'] === $zone->get_id() ) {
				$found_zone = $z;
				break;
			}
		}
		$this->assertNotNull( $found_zone, 'Expected zone not found in response' );

		$method = $found_zone['methods'][0];
		$this->assertEquals( 'free_shipping', $method['method_id'] );
		$this->assertEquals( 'min_amount', $method['requires'] );
		$this->assertEquals( '50', $method['min_amount'] );
		$this->assertEquals( 'yes', $method['ignore_discounts'] );
	}
}
