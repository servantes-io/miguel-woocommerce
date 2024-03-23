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
	public function setUp(): void {
		parent::setUp();

		$this->miguel = miguel();
	}

	/**
	 * Test instance.
	 */
	public function test_instance(): void {
		$this->assertClassHasStaticAttribute( 'instance', 'Miguel' );
	}

	/**
	 * Test version.
	 */
	public function test_version(): void {
		$this->assertEquals( '1.2.2', $this->miguel->version );
	}

	/**
	 * Test class instances.
	 */
	public function test_class_instances(): void {
		$this->assertInstanceOf( 'Miguel_API', $this->miguel->api() );
	}
}
