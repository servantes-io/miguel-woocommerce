<?php
/**
 * Test Miguel delivery methods API.
 *
 * @package Miguel\Tests
 */

/**
 * Replica of a third-party method such as toret_tcp_balikovna: title and description are
 * instance settings, and the method title differs from the customer facing title.
 */
class Miguel_Test_Balikovna_Shipping_Method extends WC_Shipping_Method {

	/**
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'miguel_test_balikovna';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'Doručení Balíkovna';
		$this->method_description = 'Toret plugin pro Balíkovna dopravní metoda';
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		$this->init();
	}

	/**
	 * Declare instance settings and hydrate them.
	 */
	public function init() {
		$this->instance_form_fields = array(
			'enabled'     => array(
				'title'   => 'Povolit',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => 'Titulek',
				'type'    => 'text',
				'default' => 'Balíkovna',
			),
			'description' => array(
				'title'   => 'Popisek',
				'type'    => 'text',
				'default' => 'Doručení Balíkovna',
			),
		);
		$this->init_instance_settings();
		$this->enabled = $this->get_option( 'enabled' );
		$this->title   = $this->get_option( 'title' );
	}
}

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
		$this->assertArrayHasKey( 'currency', $method );
		$this->assertEquals( get_woocommerce_currency(), $method['currency'] );
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

	public function test_free_shipping_method_includes_free_shipping_specific_fields() {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Free Shipping Zone' );
		$zone->save();
		$zone->add_shipping_method( 'free_shipping' );

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
		$this->assertArrayHasKey( 'requires', $method );
		$this->assertArrayHasKey( 'free_shipping', $method );
		$this->assertArrayHasKey( 'ignore_discounts', $method );
	}

	/**
	 * Add a description field with a non-empty default to flat_rate instance settings,
	 * mirroring third-party methods such as toret_tcp_balikovna.
	 *
	 * @param array $fields Instance form fields.
	 * @return array
	 */
	public function add_description_field( $fields ) {
		$fields['description'] = array(
			'title'   => 'Popisek',
			'type'    => 'text',
			'default' => 'Doručení Balíkovna',
		);
		return $fields;
	}

	/**
	 * Build a zone with one flat_rate method whose instance settings are $settings.
	 *
	 * @param string $zone_name Zone name.
	 * @param array  $settings  Instance settings to persist verbatim.
	 * @return array The formatted method from the API response.
	 */
	private function get_method_for_settings( $zone_name, $settings ) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( $zone_name );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );

		update_option( 'woocommerce_flat_rate_' . $instance_id . '_settings', $settings );

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );
		$data    = $api->get_delivery_methods( $request )->get_data();

		foreach ( $data['zones'] as $z ) {
			if ( $z['id'] === $zone->get_id() ) {
				return $z['methods'][0];
			}
		}

		$this->fail( 'Expected zone not found in response' );
	}

	public function test_description_uses_setting_value_when_it_is_not_empty() {
		add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', array( $this, 'add_description_field' ) );

		$method = $this->get_method_for_settings(
			'Description Value Zone',
			array(
				'title'       => 'Balikovna',
				'description' => 'Vyzvednete na pobocce',
			)
		);

		$this->assertSame( 'Vyzvednete na pobocce', $method['description'] );
	}

	/**
	 * The Balikovna case: the field is saved as empty text, so the response is empty.
	 * The field default must not be substituted.
	 */
	public function test_description_is_empty_when_setting_value_is_empty() {
		add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', array( $this, 'add_description_field' ) );

		$method = $this->get_method_for_settings(
			'Description Empty Zone',
			array(
				'title'       => 'Balikovna',
				'description' => '',
			)
		);

		$this->assertSame( '', $method['description'] );
	}

	public function test_description_uses_setting_default_when_key_is_absent_from_settings() {
		add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', array( $this, 'add_description_field' ) );

		$method = $this->get_method_for_settings( 'Description Unsaved Zone', array( 'title' => 'Balikovna' ) );

		$this->assertSame( 'Doručení Balíkovna', $method['description'] );
	}

	public function test_description_is_empty_when_method_has_no_description_setting() {
		$method = $this->get_method_for_settings( 'No Description Zone', array( 'title' => 'Balikovna' ) );

		$this->assertSame( '', $method['description'], 'The plugin admin blurb must not leak into description' );
	}

	public function test_title_uses_setting_value_when_it_is_not_empty() {
		$method = $this->get_method_for_settings( 'Title Value Zone', array( 'title' => 'Balikovna' ) );

		$this->assertSame( 'Balikovna', $method['title'] );
	}

	public function test_title_is_empty_when_setting_value_is_empty() {
		$method = $this->get_method_for_settings( 'Title Empty Zone', array( 'title' => '' ) );

		$this->assertSame( '', $method['title'] );
	}

	public function test_title_uses_setting_default_when_key_is_absent_from_settings() {
		$method = $this->get_method_for_settings( 'Title Unsaved Zone', array( 'tax_status' => 'taxable' ) );

		$this->assertSame( 'Flat rate', $method['title'] );
	}

	/**
	 * The title must come from the settings, not from the internal $title property
	 * exposed via get_title(), which third parties can rewrite.
	 */
	public function test_title_uses_setting_and_not_the_filtered_internal_title() {
		add_filter( 'woocommerce_shipping_method_title', array( $this, 'override_internal_title' ) );

		$method = $this->get_method_for_settings( 'Title Filter Zone', array( 'title' => 'Balikovna' ) );

		$this->assertSame( 'Balikovna', $method['title'] );
	}

	/**
	 * @param string $title Internal method title.
	 * @return string
	 */
	public function override_internal_title( $title ) {
		return 'Internal Title';
	}

	/**
	 * @param array $methods Registered shipping methods.
	 * @return array
	 */
	public function register_balikovna_method( $methods ) {
		$methods['miguel_test_balikovna'] = 'Miguel_Test_Balikovna_Shipping_Method';
		return $methods;
	}

	/**
	 * End to end replica of the reported Balikovna instance: the settings section declares
	 * title and description, description is saved as empty text, and the method title and
	 * method description differ from both.
	 */
	public function test_balikovna_instance_reports_setting_title_and_empty_description() {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_balikovna_method' ) );

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Balikovna Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'miguel_test_balikovna' );

		update_option(
			'woocommerce_miguel_test_balikovna_' . $instance_id . '_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Balikovna',
				'description' => '',
			)
		);

		$api     = new Miguel_Delivery_Methods_Api( new Miguel_Hook_Manager() );
		$request = new WP_REST_Request( 'GET', '/miguel/v1/delivery-methods' );
		$data    = $api->get_delivery_methods( $request )->get_data();

		$method = null;
		foreach ( $data['zones'] as $z ) {
			if ( $z['id'] === $zone->get_id() ) {
				$method = $z['methods'][0];
			}
		}

		$this->assertNotNull( $method, 'Expected Balikovna zone not found in response' );
		$this->assertSame( 'miguel_test_balikovna', $method['method_id'] );
		$this->assertSame( 'Balikovna', $method['title'] );
		$this->assertSame( '', $method['description'] );
	}
}
