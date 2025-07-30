<?php
/**
 * Enhanced test case with better isolation and dependency injection support
 *
 * @package Miguel\Tests
 */

class Miguel_Test_Case extends WC_Unit_Test_Case {

	/**
	 * Miguel instance for testing
	 *
	 * @var Miguel
	 */
	protected $miguel_instance;

	/**
	 * Original Miguel instance
	 *
	 * @var Miguel
	 */
	private $original_instance;

	public function setUp(): void {
		parent::setUp();

		// Store original instance if it exists
		$this->original_instance = Miguel::instance();

		// Create fresh instance for testing with isolated hooks
		Miguel::reset_instance();
		$this->miguel_instance = Miguel::instance();

		// Mock successful API responses
		Miguel_Helper_HTTP::mock_api_responses( array() );
	}

	public function tearDown(): void {
		// Clean up hooks and reset instance
		if ( $this->miguel_instance ) {
			$this->miguel_instance->get_hook_manager()->remove_all_hooks();
		}
		Miguel::reset_instance();

		// Clear HTTP mocks
		Miguel_Helper_HTTP::clear();

		parent::tearDown();
	}

	/**
	 * Create a testable service instance with mocked dependencies
	 *
	 * @param string $service_class Service class name.
	 * @param array  $mocks         Array of mocked dependencies.
	 * @return object
	 */
	protected function create_service_with_mocks( $service_class, $mocks = [] ) {
		$hook_manager = new Miguel_Hook_Manager();

		switch ( $service_class ) {
			case 'Miguel_Download':
				$api_mock         = $mocks['api'] ?? $this->createMock( Miguel_API::class );
				$file_factory     = $mocks['file_factory'] ?? null;
				$error_handler    = $mocks['error_handler'] ?? null;
				$redirect_handler = $mocks['redirect_handler'] ?? null;

				return new Miguel_Download(
					$hook_manager,
					$api_mock,
					$file_factory,
					$error_handler,
					$redirect_handler
				);

			case 'Miguel_Orders':
				$api_mock    = $mocks['api'] ?? $this->createMock( Miguel_API::class );
				$logger_mock = $mocks['logger'] ?? $this->createMock( WC_Logger::class );
				return new Miguel_Orders( $hook_manager, $api_mock, $logger_mock );

			case 'Miguel_Settings':
				return new Miguel_Settings( $hook_manager );

			case 'Miguel_Admin':
				$settings_mock = $mocks['settings'] ?? $this->createMock( Miguel_Settings::class );
				return new Miguel_Admin( $hook_manager, $settings_mock );

			default:
				throw new Exception( "Unknown service: {$service_class}" );
		}
	}

	/**
	 * Create a mock API response
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	protected function create_mock_api_response( $data = [] ) {
		$default_data = [
			'body'     => wp_json_encode( $data ),
			'response' => [ 'code' => 200 ],
		];

		return array_merge( $default_data, $data );
	}
}
