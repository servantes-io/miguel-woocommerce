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
   * @var WC_Product
   */
  protected $product;

  /**
   * @var array
   */
  protected $atts;

  /**
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

    if ( ! miguel_starts_with( $download_url, '[miguel' ) ) {
      throw new Exception( __( 'Invalid download url format.', 'miguel' ) );
    }

    $this->atts = $this->parse_shortcode_atts( $download_url );
    $this->product = $product;
    $this->download_id = $download_id;
  }

  /**
   * @return string
   */
  public function get_name() {
    return $this->atts['id'];
  }

  /**
   * @return string
   */
  public function get_format() {
    return $this->atts['format'];
  }

  /**
   * @return string
   */
  public function get_filename() {
    return sprintf( '%s.%s', $this->get_name(), $this->get_format() );
  }

  /**
   * @return int
   */
  public function get_product_id() {
    return $this->product->get_id();
  }

  /**
   * @return int
   */
  public function get_download_id() {
    return absint( $this->download_id );
  }

  /**
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
  protected function parse_shortcode_atts( $shortcode ) {
    return miguel_get_shortcode_atts( $shortcode, array(
      'id' => '',
      'format' => ''
    ) );
  }
}
