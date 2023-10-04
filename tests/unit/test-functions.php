<?php
/**
 * Test functions
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Functions extends WP_UnitTestCase {

	/**
	 * Data provider for starts_with().
	 */
	public function data_provider_test_starts_with() {
		return array(
			array( true, miguel_starts_with( '[miguel]', '[miguel' ) ),
			array( false, miguel_starts_with( '123alpha', '23a' ) ),
			array( false, miguel_starts_with( 'Testing', 'Tests' ) ),
		);
	}

	/**
	 * Test starts_with().
	 *
	 * @dataProvider data_provider_test_starts_with
	 */
	public function test_starts_with( $assert, $values ): void {
		$this->assertEquals( $assert, $values );
	}

	/**
	 * Data provider for get_shortcode_atts()
	 */
	public function data_provider_test_get_shortcode_atts() {
		return array(
			array(
				array(),
				miguel_get_shortcode_atts( '', '' ),
			),
			array(
				array(
					'type' => '',
					'param' => '',
				),
				miguel_get_shortcode_atts(
					'',
					array(
						'type' => '',
						'param' => '',
					)
				),
			),
			array(
				array(
					'id' => '123',
					'format' => 'pdf',
				),
				miguel_get_shortcode_atts(
					'[miguel id="123" format="pdf"]',
					array(
						'id' => '',
						'format' => '',
					)
				),
			),
		);
	}

	/**
	 * Test get_shortcode_atts().
	 *
	 * @dataProvider data_provider_test_get_shortcode_atts
	 */
	public function test_get_shortcode_atts( $assert, $values ): void {
		$this->assertEquals( $assert, $values );
	}
}
