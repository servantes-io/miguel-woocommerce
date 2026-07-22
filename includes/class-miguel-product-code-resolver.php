<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Resolve Miguel product codes to WooCommerce product IDs.
 *
 * @package Miguel
 */
class Miguel_Product_Code_Resolver {

	/**
	 * Cached map of product code details.
	 *
	 * @var array|null
	 */
	private $product_code_details_map = null;

	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Product_Code_Source|null $code_source Product code source.
	 */
	public function __construct( $code_source = null ) {
		$this->code_source = $code_source instanceof Miguel_Product_Code_Source ? $code_source : new Miguel_Product_Code_Source();
	}

	/**
	 * Get simple code to product ID map.
	 *
	 * @return array
	 */
	public function get_product_code_map() {
		$product_code_map = array();

		foreach ( $this->get_product_code_details_map() as $product_code => $details ) {
			$product_code_map[ $product_code ] = $details['product_id'];
		}

		return $product_code_map;
	}

	/**
	 * Get detailed map with uniqueness metadata.
	 *
	 * @return array
	 */
	public function get_product_code_details_map() {
		if ( null === $this->product_code_details_map ) {
			$this->product_code_details_map = $this->collect_product_code_details_map();

			Miguel::debug_log(
				'Collected product code details map',
				array(
					'product_code_count' => count( $this->product_code_details_map ),
					'sample_product_codes' => array_slice( array_keys( $this->product_code_details_map ), 0, 20 ),
				)
			);
		}

		return $this->product_code_details_map;
	}

	/**
	 * Resolve one product code to a unique WooCommerce product.
	 *
	 * @param string $product_code Miguel product code.
	 * @return array|WP_Error
	 */
	public function resolve_product_code( $product_code ) {
		$product_code = sanitize_text_field( trim( (string) $product_code ) );

		if ( '' === $product_code ) {
			return new WP_Error(
				'product_code.required',
				esc_html__( 'Product code is required.', 'miguel' ),
				array( 'status' => 409 )
			);
		}

		$product_code_details_map = $this->get_product_code_details_map();
		if ( ! isset( $product_code_details_map[ $product_code ] ) ) {
			return new WP_Error(
				'product_code.not_found',
				// translators: %s: product code string.
				sprintf( esc_html__( 'Product code "%s" was not found.', 'miguel' ), $product_code ),
				array(
					'status' => 409,
					'product_code' => $product_code,
					'product_ids' => array(),
				)
			);
		}

		$details = $product_code_details_map[ $product_code ];
		if ( ! $details['is_unique'] ) {
			Miguel::debug_log(
				'Product code matched multiple products',
				array(
					'product_code' => $product_code,
					'match_details' => $details,
				)
			);

			return new WP_Error(
				'product_code.ambiguous',
				// translators: %s: product code string.
				sprintf( esc_html__( 'Product code "%s" matches multiple products.', 'miguel' ), $product_code ),
				array(
					'status' => 409,
					'product_code' => $product_code,
					'product_ids' => $details['product_ids'],
					'match_count' => $details['match_count'],
				)
			);
		}

		Miguel::debug_log(
			'Resolved product code to unique product',
			array(
				'product_code' => $product_code,
				'match_details' => $details,
			)
		);

		return $details;
	}

	/**
	 * Collect map of Miguel product codes with all matched WooCommerce product IDs.
	 *
	 * @return array
	 */
	private function collect_product_code_details_map() {
		$query = new WP_Query(
			array(
				'post_type' => array( 'product', 'product_variation' ),
				'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
				'orderby' => 'ID',
				'order' => 'ASC',
			)
		);

		$product_code_entries = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$product_codes = $this->get_product_codes_from_product( $product );

			foreach ( $product_codes as $product_code ) {
				if ( ! isset( $product_code_entries[ $product_code ] ) ) {
					$product_code_entries[ $product_code ] = array();
				}

				if ( in_array( $product_id, $product_code_entries[ $product_code ], true ) ) {
					continue;
				}

				$product_code_entries[ $product_code ][] = $product_id;
			}
		}

		ksort( $product_code_entries );

		$product_code_details_map = array();
		foreach ( $product_code_entries as $product_code => $product_ids ) {
			sort( $product_ids, SORT_NUMERIC );

			$product_code_details_map[ $product_code ] = array(
				'product_code' => $product_code,
				'product_id' => (int) reset( $product_ids ),
				'product_ids' => array_values( array_map( 'intval', $product_ids ) ),
				'is_unique' => 1 === count( $product_ids ),
				'match_count' => count( $product_ids ),
			);
		}

		Miguel::debug_log(
			'Collected products for product code resolution',
			array(
				'product_post_count' => count( $query->posts ),
				'resolved_code_count' => count( $product_code_details_map ),
			)
		);

		return $product_code_details_map;
	}

	/**
	 * Extract the Miguel codes a product exposes, via the shared product code source.
	 *
	 * The resolver enables the digital-by-SKU fallback (second argument true): a
	 * downloadable product with no Miguel shortcode falls back to its bare SKU.
	 * Shortcode codes, printed-book codes (SKU + configured suffix), and the
	 * `_miguel_code` override are handled uniformly by the source.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	private function get_product_codes_from_product( $product ) {
		return $this->code_source->get_codes( $product, true );
	}
}
