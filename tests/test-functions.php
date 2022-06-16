<?php
/**
 * Test functions
 *
 * @package WC_Wosa\Tests
 */
class WC_Wosa_Test_Functions extends WP_UnitTestCase {

  /**
   * Data provider for starts_with().
   */
  public function data_provider_test_starts_with() {
    return array(
      array( true, wc_wosa_starts_with( '[wosa]', '[wosa' ) ),
      array( false, wc_wosa_starts_with( '123alpha', '23a' ) ),
      array( false, wc_wosa_starts_with( 'Testing', 'Tests' ) )
    );
  }

  /**
   * Test starts_with().
   *
   * @dataProvider data_provider_test_starts_with
   */
  public function test_starts_with( $assert, $values ) {
    $this->assertEquals( $assert, $values );
  }

  /**
   * Data provider for get_shortcode_atts()
   */
  public function data_provider_test_get_shortcode_atts() {
    return array(
      array(
        array(),
        wc_wosa_get_shortcode_atts( '', '' )
      ),
      array(
        array( 'type' => '', 'param' => '' ),
        wc_wosa_get_shortcode_atts( '', array( 'type' => '', 'param' => '' ) )
      ),
      array(
        array( 'id' => '123', 'format' => 'pdf' ),
        wc_wosa_get_shortcode_atts( '[wosa id="123" format="pdf"]', array( 'id' => '', 'format' => '' ) )
      )
    );
  }

  /**
   * Test get_shortcode_atts().
   *
   * @dataProvider data_provider_test_get_shortcode_atts
   */
  public function test_get_shortcode_atts( $assert, $values ) {
    $this->assertEquals( $assert, $values );
  }
}
