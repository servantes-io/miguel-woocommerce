<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Single source of truth for the Miguel code(s) a WooCommerce product exposes.
 *
 * @package Miguel
 */
class Miguel_Product_Code_Source {

	/**
	 * Option holding the printed-book code suffix.
	 */
	const SUFFIX_OPTION = 'miguel_print_code_suffix';

	/**
	 * Get the configured printed-book code suffix.
	 *
	 * @return string
	 */
	public static function get_print_suffix() {
		$suffix = get_option( self::SUFFIX_OPTION, '' );
		$suffix = is_string( $suffix ) ? $suffix : '';

		$filtered = apply_filters( 'miguel_print_code_suffix', $suffix );

		return is_string( $filtered ) ? $filtered : '';
	}

	/**
	 * Get the Miguel code entries a product exposes.
	 *
	 * @param WC_Product $product                       Product object.
	 * @param bool       $include_digital_sku_fallback  Whether a downloadable product with no shortcode falls back to its bare SKU. Resolver only.
	 * @return array List of array{ code:string, book_id:string, format:string, type:string }.
	 */
	public function get_items( $product, $include_digital_sku_fallback = false ) {
		if ( ! ( $product instanceof WC_Product ) ) {
			return array();
		}

		// Re-read the product fresh from the database so this stays the single
		// source of truth regardless of whether the caller's in-memory object
		// reflects the latest persisted state (e.g. downloads/meta written via
		// direct post-meta updates rather than $product->save()).
		$product_id    = $product->get_id();
		$fresh_product = $product_id ? wc_get_product( $product_id ) : false;
		if ( $fresh_product instanceof WC_Product ) {
			$product = $fresh_product;
		}

		// Rule 1: explicit override, used verbatim.
		$override = $product->get_meta( '_miguel_code', true );
		$override = is_string( $override ) ? trim( $override ) : '';
		if ( '' !== $override ) {
			return array(
				array(
					'code'    => $override,
					'book_id' => $override,
					'format'  => '',
					'type'    => $product->is_downloadable() ? 'digital' : 'print',
				),
			);
		}

		// Rule 2: digital codes from Miguel shortcodes.
		$shortcode_items = $this->get_shortcode_items( $product );
		if ( ! empty( $shortcode_items ) ) {
			return $shortcode_items;
		}

		$sku = (string) $product->get_sku();

		if ( $product->is_downloadable() ) {
			// Rule 3: digital-by-SKU fallback (resolver only).
			if ( $include_digital_sku_fallback && '' !== $sku ) {
				return array(
					array(
						'code'    => $sku,
						'book_id' => $sku,
						'format'  => '',
						'type'    => 'digital',
					),
				);
			}

			return array();
		}

		// Rule 4: printed book — non-downloadable + SKU + configured suffix.
		$suffix = self::get_print_suffix();
		if ( '' === $sku || '' === $suffix ) {
			return array();
		}

		return array(
			array(
				'code'    => $sku . $suffix,
				'book_id' => $sku,
				'format'  => 'print',
				'type'    => 'print',
			),
		);
	}

	/**
	 * Get the unique Miguel codes a product exposes.
	 *
	 * @param WC_Product $product                       Product object.
	 * @param bool       $include_digital_sku_fallback  See get_items().
	 * @return array List of unique code strings.
	 */
	public function get_codes( $product, $include_digital_sku_fallback = false ) {
		$codes = array();

		foreach ( $this->get_items( $product, $include_digital_sku_fallback ) as $item ) {
			if ( ! in_array( $item['code'], $codes, true ) ) {
				$codes[] = $item['code'];
			}
		}

		return $codes;
	}

	/**
	 * Extract digital shortcode items from a product's downloadable files.
	 *
	 * @param WC_Product $product Product object.
	 * @return array List of digital entries, deduplicated by book id + format.
	 */
	private function get_shortcode_items( $product ) {
		$items = array();
		$seen  = array();

		foreach ( $product->get_downloads() as $download ) {
			$file = '';
			if ( is_array( $download ) && isset( $download['file'] ) ) {
				$file = $download['file'];
			} elseif ( is_object( $download ) && method_exists( $download, 'get_file' ) ) {
				$file = $download->get_file();
			}

			if ( empty( $file ) || ! Miguel_Order_Utils::is_miguel_shortcode( $file ) ) {
				continue;
			}

			$atts = Miguel_Order_Utils::parse_shortcode_atts( $file );
			if ( ! $atts || empty( $atts['id'] ) ) {
				continue;
			}

			$code   = $atts['id'];
			$format = isset( $atts['format'] ) ? $atts['format'] : '';
			$key    = $code . '|' . $format;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$items[] = array(
				'code'    => $code,
				'book_id' => $code,
				'format'  => $format,
				'type'    => 'digital',
			);
		}

		return $items;
	}
}
