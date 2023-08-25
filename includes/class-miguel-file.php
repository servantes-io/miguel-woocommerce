<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * File entity
 *
 * @package Miguel
 */
class Miguel_File {

	/**
	 * Product
	 * @var WC_Product
	 */
	protected $product;

	/**
	 * Attributes
	 * @var array
	 */
	protected $atts;

	/**
	 * Download id
	 * @var int
	 */
	protected $download_id;

	/**
	 * Constructor
	 * @param int $product_id
	 * @param int $download_id
	 */
	public function __construct( $product_id, $download_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			throw new Exception( __( 'Invalid product.', 'miguel' ) );
		}

		$download_url = $product->get_file_download_path( $download_id );
		if ( ! $download_url ) {
			throw new Exception( __( 'Invalid download url.', 'miguel' ) );
		}

		if ( !miguel_starts_with($download_url, '[miguel') || !miguel_starts_with($download_url, '[wosa') ) {
			throw new Exception( __( 'Invalid download url format.', 'miguel' ) );
		}

		$this->atts = $this->parse_shortcode_atts( $download_url );
		$this->product = $product;
		$this->download_id = $download_id;
	}

	/**
	 * Get name
	 * @return string
	 */
	public function get_name() {
		return $this->atts['id'];
	}

	/**
	 * Get format
	 * @return string
	 */
	public function get_format() {
		return $this->atts['format'];
	}

	/**
	 * Get filename
	 * @return string
	 */
	public function get_filename() {
		return sprintf( '%s.%s', $this->get_name(), $this->get_format() );
	}

	/**
	 * Get product id
	 * @return int
	 */
	public function get_product_id() {
		return $this->product->get_id();
	}

	/**
	 * Get download id
	 * @return int
	 */
	public function get_download_id() {
		return absint( $this->download_id );
	}

	/**
	 * Is valid
	 * @return bool
	 */
	public function is_valid() {
		return isset( $this->atts['id'] ) && isset( $this->atts['format'] );
	}

	/**
	 * Parses shortcode attributes.
	 *
	 * @param string $shortcode
	 * @return array
	 */
	protected function parse_shortcode_atts($shortcode) {
		if (miguel_starts_with($shortcode, '[miguel')) {
			return miguel_get_shortcode_atts($shortcode, array(
				'id' => '',
				'format' => '',
			));
		} else if (miguel_starts_with($shortcode, '[wosa')) {
			$atts = miguel_get_shortcode_atts($shortcode, array(
				'book' => '',
				'format' => '',
			));
			$atts['id'] = $atts['book'];
			return $atts;
		}
	}
}
