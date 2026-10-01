<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Compare the product codes the shop's products expose with the product variants in Miguel.
 *
 * Read-only: it reads the shop's products and lists Miguel's product variants, and writes
 * nothing to either.
 *
 * @package Miguel
 */
class Miguel_Product_Pairing {

	/**
	 * The code exists on both sides.
	 */
	const STATUS_PAIRED = 'paired';

	/**
	 * A shop product exposes a code Miguel does not know.
	 */
	const STATUS_ESHOP_ONLY = 'eshop_only';

	/**
	 * A Miguel product no shop product exposes the code of.
	 */
	const STATUS_MIGUEL_ONLY = 'miguel_only';

	/**
	 * Product code resolver: the shop's codes, collected as order pairing collects them.
	 *
	 * @var Miguel_Product_Code_Resolver
	 */
	private Miguel_Product_Code_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Product_Code_Resolver|null $resolver Product code resolver.
	 */
	public function __construct( $resolver = null ) {
		$this->resolver = $resolver instanceof Miguel_Product_Code_Resolver ? $resolver : new Miguel_Product_Code_Resolver();
	}

	/**
	 * One row per product code, from either side, sorted by code.
	 *
	 * @return array|WP_Error List of array{ status:string, code:string, product_ids:int[], is_duplicate:bool, miguel_code:?string, miguel_name:?string }, or an error when Miguel's products cannot be listed.
	 */
	public function get_rows() {
		$configuration = Miguel_API::getCurrentApiConfiguration();
		if ( false === $configuration ) {
			return new WP_Error(
				'miguel.not_configured',
				__( 'The connection to Miguel is not set up: enter the API key in the Miguel settings, then try again.', 'miguel' )
			);
		}

		$client   = new Miguel_V2_Client( $configuration['url'], $configuration['token'] );
		$variants = $client->get_all_product_variants();
		if ( is_wp_error( $variants ) ) {
			return new WP_Error(
				$variants->get_error_code(),
				// translators: %s: error message from the Miguel API.
				sprintf( __( 'Could not load the products from Miguel: %s', 'miguel' ), $variants->get_error_message() )
			);
		}

		$miguel_products = array();
		foreach ( $variants as $variant ) {
			$code = isset( $variant['code'] ) && is_scalar( $variant['code'] ) ? trim( (string) $variant['code'] ) : '';
			if ( '' === $code ) {
				continue;
			}

			$miguel_products[ Miguel_Product_Code_Resolver::normalize_code( $code ) ] = array(
				'code' => $code,
				'name' => $this->get_variant_name( $variant ),
			);
		}

		$rows = array();
		foreach ( $this->resolver->get_product_code_details_map() as $code => $details ) {
			$normalized_code = Miguel_Product_Code_Resolver::normalize_code( $code );
			$miguel_product  = $miguel_products[ $normalized_code ] ?? null;
			unset( $miguel_products[ $normalized_code ] );

			$rows[] = array(
				'status' => null === $miguel_product ? self::STATUS_ESHOP_ONLY : self::STATUS_PAIRED,
				'code' => (string) $code,
				'product_ids' => $details['product_ids'],
				'is_duplicate' => ! $details['is_unique'],
				'miguel_code' => $miguel_product['code'] ?? null,
				'miguel_name' => $miguel_product['name'] ?? null,
			);
		}

		foreach ( $miguel_products as $miguel_product ) {
			$rows[] = array(
				'status' => self::STATUS_MIGUEL_ONLY,
				'code' => $miguel_product['code'],
				'product_ids' => array(),
				'is_duplicate' => false,
				'miguel_code' => $miguel_product['code'],
				'miguel_name' => $miguel_product['name'],
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( Miguel_Product_Code_Resolver::normalize_code( $a['code'] ), Miguel_Product_Code_Resolver::normalize_code( $b['code'] ) );
			}
		);

		return $rows;
	}

	/**
	 * Name of a Miguel product variant: the product's title and the variant's name, e.g. "Title (eBook)".
	 *
	 * @param array $variant Variant as listed by the Miguel API.
	 * @return string
	 */
	private function get_variant_name( $variant ) {
		$title = isset( $variant['product']['title'] ) && is_scalar( $variant['product']['title'] ) ? trim( (string) $variant['product']['title'] ) : '';
		$name  = isset( $variant['name'] ) && is_scalar( $variant['name'] ) ? trim( (string) $variant['name'] ) : '';

		if ( '' === $title ) {
			return $name;
		}

		return '' === $name ? $title : $title . ' (' . $name . ')';
	}
}
