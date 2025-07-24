<?php
/**
 * Test Miguel Order Utils functionality
 *
 * @package Miguel\Tests
 */

class Miguel_Test_Case extends WC_Unit_Test_Case {
	public function setUp(): void {
		parent::setUp();

		// Mock successful DELETE response
		Miguel_Helper_HTTP::mock_api_responses( array() );
	}

	public function tearDown(): void {
		Miguel_Helper_HTTP::clear();

		parent::tearDown();
	}
}
