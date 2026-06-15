<?php
/**
 * Tests for v2 DTO value objects.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_V2_Dtos extends WP_UnitTestCase {

	public function test_watermark_user_full(): void {
		$user = new Miguel_V2_Watermark_User( 'a@b.cz', 'cs_CZ', '42', 'John Doe', 'Main St 1, Prague' );

		$this->assertEquals(
			array(
				'id'       => '42',
				'name'     => 'John Doe',
				'address'  => 'Main St 1, Prague',
				'email'    => 'a@b.cz',
				'language' => 'cs_CZ',
			),
			$user->to_array()
		);
	}

	public function test_watermark_user_guest_has_null_optionals(): void {
		$user = new Miguel_V2_Watermark_User( 'guest@b.cz', 'en_US' );

		$arr = $user->to_array();
		$this->assertNull( $arr['id'] );
		$this->assertNull( $arr['name'] );
		$this->assertNull( $arr['address'] );
		$this->assertSame( 'guest@b.cz', $arr['email'] );
		$this->assertSame( 'en_US', $arr['language'] );
	}
}
