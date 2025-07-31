<?php
/**
 * Test Admin functionality with dependency injection
 *
 * @package Miguel\Tests\Admin
 */

use Servantes\Miguel\Interfaces\HookManagerInterface;
use Servantes\Miguel\Admin\Settings;

class AdminTest extends Miguel_Test_Case {

	/**
	 * Test that Miguel_Admin hooks are registered correctly
	 */
	public function test_admin_registers_correct_hooks() {
		$hook_manager_mock = $this->createMock( HookManagerInterface::class );

		// Expect the correct hook registration
		$hook_manager_mock->expects( $this->once() )
			->method( 'add_filter' )
			->with( 'woocommerce_get_settings_pages', $this->anything(), 10 );

		$admin = $this->create_service_with_mocks( 'Miguel_Admin', [
			'hook_manager' => $hook_manager_mock
		] );

		$admin->register_hooks();
	}

	/**
	 * Test add_settings_pages with injected settings page
	 */
	public function test_add_settings_pages_with_injected_settings() {
		$settings_mock = $this->createMock( Settings::class );

		$admin = $this->create_service_with_mocks( 'Miguel_Admin', [
			'settings' => $settings_mock,
		] );

		$pages = [ 'existing_page' ];
		$result = $admin->add_settings_pages( $pages );

		$this->assertCount( 2, $result );
		$this->assertEquals( 'existing_page', $result[0] );
		$this->assertSame( $settings_mock, $result[1] );
	}

	/**
	 * Test add_settings_pages fallback behavior
	 */
	public function test_add_settings_pages_fallback_behavior() {
		// Create admin without settings injection (should use fallback)
		$admin = new \Servantes\Miguel\Admin\Admin( new \Servantes\Miguel\Utils\HookManager() );

		$pages = [ 'existing_page' ];
		$result = $admin->add_settings_pages( $pages );

		// Should still have 2 pages (original + included settings)
		$this->assertCount( 2, $result );
		$this->assertEquals( 'existing_page', $result[0] );
		// Second page should be the included settings instance
		$this->assertInstanceOf( 'Miguel_Settings', $result[1] );
	}

	/**
	 * Test Miguel_Settings hooks are registered correctly
	 */
	public function test_settings_registers_correct_hooks() {
		$hook_manager_mock = $this->createMock( HookManagerInterface::class );

		// Expect the correct hook registrations
		$hook_manager_mock->expects( $this->exactly( 2 ) )
			->method( 'add_action' )
			->withConsecutive(
				[ 'woocommerce_settings_miguel', $this->anything() ],
				[ 'woocommerce_settings_save_miguel', $this->anything() ]
			);

		$hook_manager_mock->expects( $this->once() )
			->method( 'add_filter' )
			->with( 'woocommerce_settings_tabs_array', $this->anything(), 20 );

		$settings = $this->create_service_with_mocks( 'Miguel_Settings', [
			'hook_manager' => $hook_manager_mock
		] );

		$settings->register_hooks();
	}

	/**
	 * Test Miguel_Settings initialization
	 */
	public function test_settings_initialization() {
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );

		$this->assertEquals( 'miguel', $settings->id );
		$this->assertEquals( 'Miguel', $settings->label );
	}

	/**
	 * Test Miguel_Settings get_settings method
	 */
	public function test_settings_get_settings() {
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );
		$settings_config = $settings->get_settings();

		$this->assertIsArray( $settings_config );
		$this->assertCount( 3, $settings_config );

		// Check title section
		$this->assertEquals( 'miguel_api_options', $settings_config[0]['id'] );
		$this->assertEquals( 'title', $settings_config[0]['type'] );
		$this->assertEquals( 'Miguel API', $settings_config[0]['title'] );

		// Check API key field
		$this->assertEquals( 'miguel_api_key', $settings_config[1]['id'] );
		$this->assertEquals( 'text', $settings_config[1]['type'] );
		$this->assertEquals( 'API key', $settings_config[1]['title'] );

		// Check section end
		$this->assertEquals( 'miguel_api_options', $settings_config[2]['id'] );
		$this->assertEquals( 'sectionend', $settings_config[2]['type'] );
	}

	/**
	 * Test Miguel_Settings output method
	 */
	public function test_settings_output() {
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );

		// Capture output buffer
		ob_start();
		$settings->output();
		$output = ob_get_clean();

		// Should have some output (WordPress settings form HTML)
		$this->assertNotEmpty( $output );
	}

	/**
	 * Test Miguel_Settings save method
	 */
	public function test_settings_save() {
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );

		// Mock the $_POST data
		$_POST['miguel_api_key'] = 'test-api-key-123';

		// This should not throw any errors
		$settings->save();

		// Verify the option was saved
		$this->assertEquals( 'test-api-key-123', get_option( 'miguel_api_key' ) );

		// Clean up
		delete_option( 'miguel_api_key' );
		unset( $_POST['miguel_api_key'] );
	}



	/**
	 * Test admin integration with both classes
	 */
	public function test_admin_integration_flow() {
		// Create settings instance
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );

		// Create admin instance with settings
		$admin = $this->create_service_with_mocks( 'Miguel_Admin', [
			'settings' => $settings,
		] );

		// Test the complete flow - verify settings injection works
		$pages = [];
		$result = $admin->add_settings_pages( $pages );

		$this->assertCount( 1, $result );
		$this->assertSame( $settings, $result[0] );
	}

	/**
	 * Test Miguel_Settings inheritance from WC_Settings_Page
	 */
	public function test_settings_extends_wc_settings_page() {
		$settings = $this->create_service_with_mocks( 'Miguel_Settings' );

		$this->assertInstanceOf( 'WC_Settings_Page', $settings );
		$this->assertTrue( method_exists( $settings, 'add_settings_page' ) );
	}
}
