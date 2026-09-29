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

	public function test_settings_expose_the_deleted_order_statuses_picker() {
		$settings = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_settings();

		$field = null;
		foreach ( $settings as $candidate ) {
			if ( isset( $candidate['id'] ) && Miguel_Orders::DELETED_STATUSES_OPTION === $candidate['id'] ) {
				$field = $candidate;
			}
		}

		$this->assertNotNull( $field );
		$this->assertSame( 'multiselect', $field['type'] );
		$this->assertSame( Miguel_Orders::DEFAULT_DELETED_STATUSES, $field['default'] );
		$this->assertArrayHasKey( 'refunded', $field['options'] );
		$this->assertArrayNotHasKey( 'trash', $field['options'],
			'trash is a post status, not an order status, and is always treated as a deletion' );
		$this->assertSame( 'miguel_order_options', $this->section_of( $settings, Miguel_Orders::DELETED_STATUSES_OPTION ) );
	}

	public function test_sections_include_product_pairing() {
		$sections = ( new Miguel_Settings( new Miguel_Hook_Manager() ) )->get_sections();

		$this->assertArrayHasKey( '', $sections );
		$this->assertArrayHasKey( Miguel_Settings::PRODUCT_PAIRING_SECTION, $sections );
	}

	public function test_product_pairing_section_renders_the_table() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Miguel_API::API_KEY_OPTION, 'tok123' );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_name( 'Shop <b>Book</b>' );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->update_meta_data( '_miguel_code', 'PAIRED-1' );
		$product->save();

		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'GET' => array(
					'body'     => wp_json_encode(
						array(
							'data' => array(
								array(
									'code'    => 'paired-1',
									'name'    => 'eBook',
									'product' => array( 'title' => 'Miguel Book' ),
								),
							),
							'meta' => array( 'nextPage' => null ),
						)
					),
					'response' => array( 'code' => 200 ),
				),
			)
		);

		$html = $this->output_section( Miguel_Settings::PRODUCT_PAIRING_SECTION );

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'PAIRED-1', $html );
		$this->assertStringContainsString( __( 'Paired', 'miguel' ), $html );
		$this->assertStringContainsString( 'Shop &lt;b&gt;Book&lt;/b&gt;', $html );
		$this->assertStringContainsString( esc_url( get_edit_post_link( $product->get_id(), 'raw' ) ), $html );
		$this->assertStringContainsString( 'Miguel Book (eBook)', $html );
		$this->assertStringContainsString( 'paired-1', $html, "Miguel's spelling of a code differing in case is shown" );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	public function test_product_pairing_section_shows_an_error_without_a_connection() {
		delete_option( Miguel_API::API_KEY_OPTION );

		$html = $this->output_section( Miguel_Settings::PRODUCT_PAIRING_SECTION );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_product_pairing_section_writes_nothing() {
		update_option( Miguel_API::API_KEY_OPTION, 'tok123' );
		update_option( 'miguel_api_connected', 'yes' );

		$this->output_section( Miguel_Settings::PRODUCT_PAIRING_SECTION );
		$this->assertSame( array( 'GET' ), array_unique( array_column( Miguel_Helper_HTTP::get_requests(), 'method' ) ) );

		Miguel_Helper_HTTP::mock_api_responses( array() );
		$GLOBALS['current_section'] = Miguel_Settings::PRODUCT_PAIRING_SECTION;
		( new Miguel_Settings( new Miguel_Hook_Manager() ) )->save();

		$this->assertCount( 0, Miguel_Helper_HTTP::get_requests(), 'saving the pairing section must not connect to Miguel' );
		$this->assertSame( 'yes', get_option( 'miguel_api_connected' ) );
		$this->assertSame( 'tok123', get_option( Miguel_API::API_KEY_OPTION ) );
	}

	public function setUp(): void {
		parent::setUp();
		// The settings page is admin-only, so the plugin does not load it under tests.
		require_once dirname( dirname( dirname( __FILE__ ) ) ) . '/includes/admin/class-miguel-settings.php';
	}

	public function tearDown(): void {
		unset( $GLOBALS['current_section'], $GLOBALS['hide_save_button'] );
		delete_option( Miguel_API::API_KEY_OPTION );
		delete_option( 'miguel_api_connected' );
		parent::tearDown();
	}

	/**
	 * Render one section of the Miguel settings tab.
	 *
	 * @param string $section Section id.
	 * @return string HTML.
	 */
	private function output_section( $section ) {
		$GLOBALS['current_section'] = $section;

		ob_start();
		( new Miguel_Settings( new Miguel_Hook_Manager() ) )->output();

		return (string) ob_get_clean();
	}
}
