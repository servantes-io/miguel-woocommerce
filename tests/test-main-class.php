<?php
/**
 * Test the main class
 *
 * @package WC_Wosa\Tests 
 */
class WC_Wosa_Test_Main_Class extends WP_UnitTestCase {

  /**
   * Setup test.
   */
  public function setUp() {
    parent::setUp();

    $this->wosa = WC_Wosa();
  }

  /**
   * Test instance.
   */
  public function test_instance() {
    $this->assertClassHasStaticAttribute( 'instance', 'WC_Wosa' );
  }

  /**
   * Test version.
   */
  public function test_version() {
    $this->assertEquals( '0.1.0', $this->wosa->version );
  }

  /**
   * Test class instances.
   */
  public function test_class_instances() {
    $this->assertInstanceOf( 'WC_Wosa_API', $this->wosa->api() );
  }
}
