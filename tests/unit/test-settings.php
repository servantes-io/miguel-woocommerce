<?php
/**
 * Test Miguel_Settings.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Settings extends Miguel_Test_Case {

	public function test_settings_include_print_code_suffix_field() {
		// Include the class file
		require_once dirname( dirname( dirname( __FILE__ ) ) ) . '/includes/admin/class-miguel-settings.php';

		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$ids = array_column( $settings, 'id' );
		$this->assertContains( Miguel_Product_Code_Source::SUFFIX_OPTION, $ids );
	}

	public function test_settings_expose_both_order_status_targets() {
		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$by_id = array();
		foreach ( $settings as $field ) {
			if ( isset( $field['id'] ) ) {
				$by_id[ $field['id'] ] = $field;
			}
		}

		$this->assertArrayHasKey( Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION, $by_id );
		$this->assertArrayHasKey( Miguel_Order_Finished_Api::STATUS_MIXED_OPTION, $by_id );

		$miguel_only = $by_id[ Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION ];
		$this->assertSame( 'select', $miguel_only['type'] );
		$this->assertSame( '', $miguel_only['default'] );
		$this->assertArrayHasKey( '', $miguel_only['options'] );
		$this->assertArrayHasKey( 'completed', $miguel_only['options'] );
	}
}
