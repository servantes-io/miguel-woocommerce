<?php
/**
 * Test the product pairing table's data: shop codes against Miguel's product variants.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Product_Pairing extends Miguel_Test_Case {

	public function setUp(): void {
		parent::setUp();
		update_option( Miguel_API::API_KEY_OPTION, 'tok123' );
	}

	public function tearDown(): void {
		delete_option( Miguel_API::API_KEY_OPTION );
		parent::tearDown();
	}

	public function test_each_code_is_paired_or_on_one_side_only() {
		$paired    = $this->create_product_with_miguel_code( 'paired-1' );
		$upper     = $this->create_product_with_miguel_code( 'ABC-1' );
		$shop_only = $this->create_product_with_miguel_code( 'shop-only' );
		$this->mock_miguel_pages(
			array(
				array(
					$this->variant( 'paired-1' ),
					$this->variant( 'abc-1' ),
					$this->variant( 'miguel-only' ),
					array( 'name' => 'no code' ),
				),
			)
		);

		$rows = $this->rows_by_code( ( new Miguel_Product_Pairing() )->get_rows() );

		$this->assertSame( array( 'ABC-1', 'miguel-only', 'paired-1', 'shop-only' ), array_keys( $rows ) );

		$this->assertSame( Miguel_Product_Pairing::STATUS_PAIRED, $rows['paired-1']['status'] );
		$this->assertSame( array( $paired->get_id() ), $rows['paired-1']['product_ids'] );
		$this->assertSame( 'Title paired-1 (eBook)', $rows['paired-1']['miguel_name'] );

		// Case does not matter: ABC-1 in the shop and abc-1 in Miguel are one paired row.
		$this->assertSame( Miguel_Product_Pairing::STATUS_PAIRED, $rows['ABC-1']['status'] );
		$this->assertSame( array( $upper->get_id() ), $rows['ABC-1']['product_ids'] );
		$this->assertSame( 'abc-1', $rows['ABC-1']['miguel_code'] );

		$this->assertSame( Miguel_Product_Pairing::STATUS_ESHOP_ONLY, $rows['shop-only']['status'] );
		$this->assertSame( array( $shop_only->get_id() ), $rows['shop-only']['product_ids'] );
		$this->assertNull( $rows['shop-only']['miguel_name'] );

		$this->assertSame( Miguel_Product_Pairing::STATUS_MIGUEL_ONLY, $rows['miguel-only']['status'] );
		$this->assertSame( array(), $rows['miguel-only']['product_ids'] );
		$this->assertSame( 'Title miguel-only (eBook)', $rows['miguel-only']['miguel_name'] );

		$this->assertFalse( $rows['paired-1']['is_duplicate'] );
	}

	public function test_shop_codes_differing_only_in_case_are_one_duplicate_row() {
		$upper = $this->create_product_with_miguel_code( 'DUP-1' );
		$lower = $this->create_product_with_miguel_code( 'dup-1' );
		$this->mock_miguel_pages( array( array( $this->variant( 'Dup-1' ) ) ) );

		$rows = ( new Miguel_Product_Pairing() )->get_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( Miguel_Product_Pairing::STATUS_PAIRED, $rows[0]['status'] );
		$this->assertTrue( $rows[0]['is_duplicate'] );
		$this->assertSame( array( $upper->get_id(), $lower->get_id() ), $rows[0]['product_ids'] );
	}

	public function test_miguel_products_from_every_page_are_compared() {
		$this->create_product_with_miguel_code( 'second-page' );
		$this->mock_miguel_pages(
			array(
				array( $this->variant( 'first-page' ) ),
				array( $this->variant( 'second-page' ) ),
			)
		);

		$rows = $this->rows_by_code( ( new Miguel_Product_Pairing() )->get_rows() );

		$this->assertSame( Miguel_Product_Pairing::STATUS_MIGUEL_ONLY, $rows['first-page']['status'] );
		$this->assertSame( Miguel_Product_Pairing::STATUS_PAIRED, $rows['second-page']['status'] );
	}

	public function test_missing_connection_is_an_error_and_calls_nothing() {
		delete_option( Miguel_API::API_KEY_OPTION );
		$this->create_product_with_miguel_code( 'shop-only' );

		$result = ( new Miguel_Product_Pairing() )->get_rows();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'miguel.not_configured', $result->get_error_code() );
		$this->assertCount( 0, Miguel_Helper_HTTP::get_requests() );
	}

	public function test_failed_api_call_is_an_error() {
		$this->create_product_with_miguel_code( 'shop-only' );
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'GET' => array(
					'body'     => wp_json_encode( array( 'title' => 'Internal Server Error' ) ),
					'response' => array( 'code' => 500 ),
				),
			)
		);

		$result = ( new Miguel_Product_Pairing() )->get_rows();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'Internal Server Error', $result->get_error_message() );
	}

	/**
	 * Mock GET v2/product-variants, one response per page.
	 *
	 * @param array $pages Variants of each page.
	 */
	private function mock_miguel_pages( $pages ) {
		$responses = array();
		foreach ( $pages as $index => $variants ) {
			$page = $index + 1;

			$responses[ 'page=' . $page . '&' ] = array(
				'body'     => wp_json_encode(
					array(
						'data' => $variants,
						'meta' => array( 'nextPage' => $page < count( $pages ) ? $page + 1 : null ),
					)
				),
				'response' => array( 'code' => 200 ),
			);
		}

		Miguel_Helper_HTTP::mock_api_responses( $responses );
	}

	/**
	 * A product variant as Miguel lists it.
	 *
	 * @param string $code Variant code.
	 * @return array
	 */
	private function variant( $code ) {
		return array(
			'code'    => $code,
			'name'    => 'eBook',
			'product' => array( 'title' => 'Title ' . $code ),
		);
	}

	/**
	 * @param array $rows Rows from get_rows().
	 * @return array Rows keyed by code, in the order returned.
	 */
	private function rows_by_code( $rows ) {
		$this->assertIsArray( $rows );

		return array_column( $rows, null, 'code' );
	}

	/**
	 * A downloadable product whose Miguel code is set through the `_miguel_code` meta.
	 *
	 * @param string $code Miguel code.
	 * @return WC_Product
	 */
	private function create_product_with_miguel_code( $code ) {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->update_meta_data( '_miguel_code', $code );
		$product->save();

		return $product;
	}
}
