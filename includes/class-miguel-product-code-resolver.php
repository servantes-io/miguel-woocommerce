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
					'product_code_details' => $this->product_code_details_map,
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
		$collected_products = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$product_codes = $this->get_product_codes_from_product( $product );
			$collected_products[] = array(
				'product_id' => $product->get_id(),
				'sku' => $product->get_sku(),
				'name' => $product->get_name(),
				'product_codes' => $product_codes,
			);

			foreach ( $product_codes as $product_code ) {
				if ( ! isset( $product_code_entries[ $product_code ] ) ) {
					$product_code_entries[ $product_code ] = array();
				}

				if ( in_array( $product->get_id(), $product_code_entries[ $product_code ], true ) ) {
					continue;
				}

				$product_code_entries[ $product_code ][] = $product->get_id();
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
				'products' => $collected_products,
			)
		);

		return $product_code_details_map;
	}

	/**
	 * Extract Miguel product codes from downloadable files, falling back to SKU.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	private function get_product_codes_from_product( $product ) {
		$product_codes = array();
		$downloads = $product->get_downloads();

		foreach ( $downloads as $download ) {
			$shortcode = '';

			if ( is_array( $download ) && isset( $download['file'] ) ) {
				$shortcode = $download['file'];
			} elseif ( is_object( $download ) && method_exists( $download, 'get_file' ) ) {
				$shortcode = $download->get_file();
			}

			if ( empty( $shortcode ) || ! Miguel_Order_Utils::is_miguel_shortcode( $shortcode ) ) {
				continue;
			}

			$product_code = Miguel_Order_Utils::extract_miguel_code( $shortcode );
			if ( empty( $product_code ) || in_array( $product_code, $product_codes, true ) ) {
				continue;
			}

			$product_codes[] = $product_code;
		}

		// Fall back to SKU when no shortcode-based codes were found.
		if ( empty( $product_codes ) ) {
			$sku = $product->get_sku();
			if ( ! empty( $sku ) ) {
				$product_codes[] = $sku;
			}
		}

		return $product_codes;
	}
}
