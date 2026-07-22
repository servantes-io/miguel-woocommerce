<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Single source of truth for the Miguel code(s) a WooCommerce product exposes.
 *
 * NOTE: get_items()/get_codes() reload the product fresh via wc_get_product()
 * by ID before reading its downloads and meta. WC_Product caches the
 * `downloads` prop in memory, so a caller holding an object whose downloadable
 * files were written out-of-band (the resolver builds products from a WP_Query
 * ID list; test helpers write `_downloadable_files` via update_post_meta) could
 * otherwise read stale downloads. The reload keeps this single source of truth
 * correct regardless of the caller's object state. Cost: one extra WC_Product
 * hydration per call — no extra DB round-trips when the post/meta object cache
 * is warm.
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

		return is_string( $filtered ) ? trim( $filtered ) : '';
	}

	/**
	 * Get the Miguel code entries a product exposes.
	 *
	 * @param WC_Product $product                       Product object.
	 * @param bool       $include_digital_sku_fallback  Whether a shortcode-less product falls back to its bare SKU — a downloadable product (digital) or, when no suffix is configured, a non-downloadable one (print). Resolver only.
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

		// Printed book — non-downloadable + SKU.
		if ( '' === $sku ) {
			return array();
		}

		// Rule 4: a configured suffix namespaces the print code (collision-proof
		// against a same-slug e-book twin).
		$suffix = self::get_print_suffix();
		if ( '' !== $suffix ) {
			return array(
				array(
					'code'    => $sku . $suffix,
					'book_id' => $sku,
					'format'  => 'print',
					'type'    => 'print',
				),
			);
		}

		// Rule 5: print-by-SKU fallback (resolver only). With no suffix configured,
		// the printed book is addressable by its bare SKU, mirroring the digital-by-SKU
		// fallback. Export stays strict (nothing) so we never invent an unnamespaced
		// code for outbound sync/discovery. If a same-slug e-book also exposes this
		// SKU, the resolver reports product_code.ambiguous — configure a suffix to split them.
		if ( $include_digital_sku_fallback ) {
			return array(
				array(
					'code'    => $sku,
					'book_id' => $sku,
					'format'  => 'print',
					'type'    => 'print',
				),
			);
		}

		return array();
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
