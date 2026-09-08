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

	public function test_settings_are_grouped_into_four_closed_sections() {
		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$opened = array();
		$closed = array();
		foreach ( $settings as $field ) {
			if ( 'title' === $field['type'] ) {
				$opened[] = $field['id'];
			} elseif ( 'sectionend' === $field['type'] ) {
				$closed[] = $field['id'];
			}
		}

		$expected = array(
			'miguel_api_options',
			'miguel_order_options',
			'miguel_order_status_options',
			'miguel_product_options',
		);

		$this->assertSame( $expected, $opened );
		$this->assertSame( $expected, $closed, 'every section must be closed, in the order it was opened' );
	}

	public function test_each_option_lands_in_its_own_section() {
		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$this->assertSame( 'miguel_api_options', $this->section_of( $settings, Miguel_API::API_KEY_OPTION ) );
		$this->assertSame( 'miguel_api_options', $this->section_of( $settings, Miguel_API::SERVER_OPTION ) );
		$this->assertSame( 'miguel_order_options', $this->section_of( $settings, Miguel_Orders::SEND_EMAIL_OPTION ) );
		$this->assertSame(
			'miguel_order_status_options',
			$this->section_of( $settings, Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION )
		);
		$this->assertSame(
			'miguel_order_status_options',
			$this->section_of( $settings, Miguel_Order_Finished_Api::STATUS_MIXED_OPTION )
		);
		$this->assertSame(
			'miguel_product_options',
			$this->section_of( $settings, Miguel_Product_Code_Source::SUFFIX_OPTION )
		);
	}

	/**
	 * Which section a given option field sits inside.
	 *
	 * @param array  $settings  Output of Miguel_Settings::get_settings().
	 * @param string $option_id The option to locate.
	 * @return string|null Section id, or null when the option is outside every section.
	 */
	private function section_of( $settings, $option_id ) {
		$current = null;

		foreach ( $settings as $field ) {
			if ( 'title' === $field['type'] ) {
				$current = $field['id'];
			} elseif ( 'sectionend' === $field['type'] ) {
				$current = null;
			} elseif ( isset( $field['id'] ) && $option_id === $field['id'] ) {
				return $current;
			}
		}

		return null;
	}
}
