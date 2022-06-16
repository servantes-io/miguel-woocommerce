<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * File entity
 *
 * @package WC_Wosa
 */
class WC_Wosa_File {

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
      throw new Exception( __( 'Invalid product.', 'wc-wosa' ) ); 
    }

    $download_url = $product->get_file_download_path( $download_id );
    if ( ! $download_url ) {
      throw new Exception( __( 'Invalid download url.', 'wc-wosa' ) ); 
    }

    if ( ! wc_wosa_starts_with( $download_url, '[wosa' ) ) {
      throw new Exception( __( 'Invalid download url format.', 'wc-wosa' ) ); 
    }

    $this->atts = $this->parse_shortcode_atts( $download_url );
    $this->product = $product;
    $this->download_id = $download_id;
  }

  /**
   * @return string
   */
  public function get_name() {
    return $this->atts['book'];
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
    return isset( $this->atts['book'] ) && isset( $this->atts['format'] );
  }

  /**
   * Parses shortcode attributes.
   *
   * @param string $shortcode
   * @return array
   */
  protected function parse_shortcode_atts( $shortcode ) {
    return wc_wosa_get_shortcode_atts( $shortcode, array(
      'book' => '',
      'format' => ''
    ) );
  }
}
