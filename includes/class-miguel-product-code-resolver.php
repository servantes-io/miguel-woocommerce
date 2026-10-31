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
	 * Transient holding the code => product IDs map between requests.
	 */
	const CACHE_TRANSIENT = 'miguel_product_code_map';

	/**
	 * How long the map stands at most, for catalogue writes that bypass WordPress (direct SQL).
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Product meta whose change can change a product's codes.
	 */
	const CODE_META_KEYS = array( '_sku', '_downloadable', '_downloadable_files', '_miguel_code' );

	/**
	 * Cached map of product code details.
	 *
	 * @var array|null
	 */
	private ?array $product_code_details_map = null;

	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private Miguel_Product_Code_Source $code_source;

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
			$this->product_code_details_map = $this->build_product_code_details_map( $this->get_product_code_entries() );

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
	 * Register the hooks that drop the cached map when a product's codes may have changed.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public static function register_cache_hooks( Miguel_Hook_Manager_Interface $hook_manager ) {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			$hook_manager->add_action( $hook, array( self::class, 'flush_cache_for_meta' ), 10, 3 );
		}
		$hook_manager->add_action( 'transition_post_status', array( self::class, 'flush_cache_for_status' ), 10, 3 );
		$hook_manager->add_action( 'deleted_post', array( self::class, 'flush_cache_for_post' ) );
	}

	/**
	 * Drop the cached map.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_TRANSIENT );
	}

	/**
	 * Drop the cached map when a product's code meta changes.
	 *
	 * WooCommerce before 9 drops a product's cached meta only on `updated_post_meta`, so meta added
	 * or deleted directly would still be read stale when the map is rebuilt: drop it here too.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function flush_cache_for_meta( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, self::CODE_META_KEYS, true ) ) {
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'object_' . $object_id );
			}
			self::flush_cache_for_post( $object_id );
		}
	}

	/**
	 * Drop the cached map when a product is published, unpublished, trashed or restored.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function flush_cache_for_status( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && $post instanceof WP_Post ) {
			self::flush_cache_for_post( $post->ID );
		}
	}

	/**
	 * Drop the cached map when the post is a product or a variation.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function flush_cache_for_post( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Miguel product code => all matched WooCommerce product IDs, from the cache or the catalogue.
	 *
	 * Collecting it loads every product of the shop, which takes seconds on a large catalogue, so it
	 * is kept between requests. The print suffix is stored with it: a change of the option or of
	 * the filter shows as a different suffix.
	 *
	 * @return array
	 */
	private function get_product_code_entries() {
		$suffix = Miguel_Product_Code_Source::get_print_suffix();

		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['entries'] ) && ( $cached['suffix'] ?? null ) === $suffix ) {
			return $cached['entries'];
		}

		$entries = $this->collect_product_code_entries();
		set_transient(
			self::CACHE_TRANSIENT,
			array(
				'suffix'  => $suffix,
				'entries' => $entries,
			),
			self::CACHE_TTL
		);

		return $entries;
	}

	/**
	 * Collect Miguel product codes with all matched WooCommerce product IDs.
	 *
	 * @return array Product code => sorted product IDs.
	 */
	private function collect_product_code_entries() {
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

				$product_code_entries[ $product_code ][] = (int) $product_id;
			}
		}

		ksort( $product_code_entries );
		foreach ( $product_code_entries as &$product_ids ) {
			sort( $product_ids, SORT_NUMERIC );
		}
		unset( $product_ids );

		Miguel::debug_log(
			'Collected products for product code resolution',
			array(
				'product_post_count' => count( $query->posts ),
				'resolved_code_count' => count( $product_code_entries ),
			)
		);

		return $product_code_entries;
	}

	/**
	 * The detailed map with uniqueness metadata.
	 *
	 * @param array $product_code_entries Result of get_product_code_entries().
	 * @return array
	 */
	private function build_product_code_details_map( array $product_code_entries ) {
		$product_code_details_map = array();
		foreach ( $product_code_entries as $product_code => $product_ids ) {
			$product_code_details_map[ $product_code ] = array(
				'product_code' => $product_code,
				'product_id' => (int) reset( $product_ids ),
				'product_ids' => array_values( array_map( 'intval', $product_ids ) ),
				'is_unique' => 1 === count( $product_ids ),
				'match_count' => count( $product_ids ),
			);
		}

		return $product_code_details_map;
	}

	/**
	 * Extract the Miguel codes a product exposes, via the shared product code source.
	 *
	 * The resolver enables the bare-SKU fallback (second argument true): a
	 * shortcode-less product falls back to its bare SKU — a downloadable product
	 * (digital) or, when no suffix is configured, a non-downloadable one (print).
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
