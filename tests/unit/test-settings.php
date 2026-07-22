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
}
