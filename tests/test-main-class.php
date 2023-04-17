<?php
/**
 * Test the main class
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Main_Class extends WP_UnitTestCase {

  /**
   * Setup test.
   */
  public function setUp() {
    parent::setUp();

    $this->miguel = Miguel();
  }

  /**
   * Test instance.
   */
  public function test_instance() {
    $this->assertClassHasStaticAttribute( 'instance', 'Miguel' );
  }

  /**
   * Test version.
   */
  public function test_version() {
    $this->assertEquals( '1.1.2', $this->miguel->version );
  }

  /**
   * Test class instances.
   */
  public function test_class_instances() {
    $this->assertInstanceOf( 'Miguel_API', $this->miguel->api() );
  }
}
