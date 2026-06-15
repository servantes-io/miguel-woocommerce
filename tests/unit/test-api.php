<?php
/**
 * Test API configuration helpers.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_API extends WP_UnitTestCase {

	public function test_get_server_url_for_environments(): void {
		$this->assertSame( 'https://miguel.servantes.cz', Miguel_API::getServerUrl( Miguel_API::ENV_PROD ) );
		$this->assertSame( 'https://miguel-test.servantes.cz', Miguel_API::getServerUrl( Miguel_API::ENV_TEST ) );
		$this->assertFalse( Miguel_API::getServerUrl( 'nonsense' ) );
	}

	public function test_get_enabled_reflects_api_key_option(): void {
		update_option( Miguel_API::API_KEY_OPTION, '' );
		$this->assertFalse( Miguel_API::getEnabled() );

		update_option( Miguel_API::API_KEY_OPTION, 'abc123' );
		$this->assertTrue( Miguel_API::getEnabled() );

		delete_option( Miguel_API::API_KEY_OPTION );
	}

	public function test_get_current_api_configuration(): void {
		update_option( Miguel_API::API_KEY_OPTION, 'abc123' );
		update_option( Miguel_API::SERVER_OPTION, Miguel_API::ENV_TEST );

		$config = Miguel_API::getCurrentApiConfiguration();

		$this->assertSame( 'https://miguel-test.servantes.cz', $config['url'] );
		$this->assertSame( 'abc123', $config['token'] );
		$this->assertSame( Miguel_API::ENV_TEST, $config['environment'] );

		delete_option( Miguel_API::API_KEY_OPTION );
		delete_option( Miguel_API::SERVER_OPTION );
	}
}
