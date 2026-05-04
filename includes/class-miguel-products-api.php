<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API for Miguel products.
 *
 * @package Miguel
 */
class Miguel_Products_Api {

	/**
	 * Hook manager instance.
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'miguel/v1',
			'/products',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Validate API access by bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function validate_api_access( $request ) {
		try {
			$provided_token = $this->get_bearer_token( $request );

			if ( '' === $provided_token ) {
				return new WP_Error(
					'api_key.not_set',
					esc_html__( 'Authorization bearer token is missing.', 'miguel' ),
					array( 'status' => 401 )
				);
			}

			$configuration = Miguel_API::getCurrentApiConfiguration();
			if ( false === $configuration ) {
				return new WP_Error(
					'configuration.not_set',
					esc_html__( 'Miguel API configuration is not set.', 'miguel' ),
					array( 'status' => 500 )
				);
			}

			$configured_token = isset( $configuration['token'] ) ? (string) $configuration['token'] : '';
			if ( '' === $configured_token ) {
				return new WP_Error(
					'api_key.not_set',
					esc_html__( 'Miguel API key is not configured.', 'miguel' ),
					array( 'status' => 500 )
				);
			}

			if ( ! hash_equals( $configured_token, $provided_token ) ) {
				return new WP_Error(
					'api_key.invalid',
					esc_html__( 'Invalid API token.', 'miguel' ),
					array( 'status' => 403 )
				);
			}

			return true;
		} catch ( Exception $exception ) {
			Miguel::log( 'validate_api_access failed: ' . $exception->getMessage(), 'error' );

			return new WP_Error(
				'unknown.error',
				esc_html__( 'Unexpected authentication error.', 'miguel' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Read bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private function get_bearer_token( $request ) {
		$authorization = $request->get_header( 'authorization' );

		if ( empty( $authorization ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['X-HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['X-HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $header_value ) {
					if ( 0 === strcasecmp( (string) $header_name, 'Authorization' ) ) {
						$authorization = (string) $header_value;
						break;
					}
				}
			}
		}

		if ( empty( $authorization ) && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $header_value ) {
					if ( 0 === strcasecmp( (string) $header_name, 'Authorization' ) ) {
						$authorization = (string) $header_value;
						break;
					}
				}
			}
		}

		if ( empty( $authorization ) ) {
			return '';
		}

		if ( preg_match( '/Bearer\s+(.*)$/i', $authorization, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Get Miguel products with prices.
	 *
	 * @return WP_REST_Response
	 */
	public function get_products() {
		$products = $this->collect_miguel_products();

		return new WP_REST_Response(
			array(
				'count' => count( $products ),
				'currency' => get_woocommerce_currency(),
				'products' => $products,
			),
			200
		);
	}

	/**
	 * Collect all WooCommerce products.
	 *
	 * @return array
	 */
	private function collect_miguel_products() {
		$query = new WP_Query(
			array(
				'post_type' => array( 'product', 'product_variation' ),
				'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
			)
		);

		$product_ids = $query->posts;
		$products = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$price_data = $this->get_price_data( $product );
			$stock_data = $this->get_stock_data( $product );
			$miguel_items = $this->get_miguel_items_from_product( $product );

			$products[] = array(
				'id' => $product->get_id(),

				'name' => $product->get_name(),
				'status' => 'publish' === $product->get_status(),
				'type' => $product->get_type(),
				'sku' => $product->get_sku(),
				'currency' => get_woocommerce_currency(),
				'price' => $price_data['price_with_tax'],
				'price_without_tax' => $price_data['price_without_tax'],
				'tax' => $price_data['tax'],
				'tax_total' => $price_data['tax_total'],
				'in_stock' => $stock_data['in_stock'],
				'stock_status' => $stock_data['stock_status'],
				'manage_stock' => $stock_data['manage_stock'],
				'stock_quantity' => $stock_data['stock_quantity'],
				'backorders_allowed' => $stock_data['backorders_allowed'],
				'sold_individually' => $product->is_sold_individually(),
				'regular_price' => $this->normalize_price( $product->get_regular_price() ),
				'sale_price' => $this->normalize_price( $product->get_sale_price() ),
				'parent_id' => $product->get_parent_id(),
				'miguel_items' => $miguel_items,
			);
		}

		return $products;
	}

	/**
	 * Extract Miguel shortcodes from downloadable files.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	private function get_miguel_items_from_product( $product ) {
		$items = array();
		$downloads = $product->get_downloads();

		foreach ( $downloads as $download ) {
			$shortcode = '';

			if ( is_array( $download ) && isset( $download['file'] ) ) {
				$shortcode = $download['file'];
			} elseif ( is_object( $download ) && method_exists( $download, 'get_file' ) ) {
				$shortcode = $download->get_file();
			}

			if ( empty( $shortcode ) ) {
				continue;
			}

			if ( ! Miguel_Order_Utils::is_miguel_shortcode( $shortcode ) ) {
				continue;
			}

			$atts = Miguel_Order_Utils::parse_shortcode_atts( $shortcode );
			if ( ! $atts || empty( $atts['id'] ) ) {
				continue;
			}

			$items[] = array(
				'book_id' => $atts['id'],
				'format' => isset( $atts['format'] ) ? $atts['format'] : '',
			);
		}

		return $items;
	}

	/**
	 * Normalize WooCommerce price format.
	 *
	 * @param string $value Price value.
	 * @return float|null
	 */
	private function normalize_price( $value ) {
		if ( '' === $value || null === $value ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Get tax-related price fields for a product.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	private function get_price_data( $product ) {
		$price = $this->normalize_price( $product->get_price() );

		if ( null === $price ) {
			return array(
				'price_with_tax' => null,
				'price_without_tax' => null,
				'tax' => null,
				'tax_total' => null,
			);
		}

		$tax_rates = WC_Tax::get_rates( $product->get_tax_class() );

		$price_without_tax = (float) wc_get_price_excluding_tax(
			$product,
			array(
				'price' => $price,
			)
		);

		$price_with_tax = (float) wc_get_price_including_tax(
			$product,
			array(
				'price' => $price,
			)
		);

		$tax_amounts = WC_Tax::calc_tax( $price_without_tax, $tax_rates, false );
		$tax_total = (float) array_sum( $tax_amounts );
		$tax_rate = $this->get_total_tax_rate( $tax_rates );

		return array(
			'price_with_tax' => $this->normalize_price( wc_format_decimal( $price_with_tax, wc_get_price_decimals() ) ),
			'price_without_tax' => $this->normalize_price( wc_format_decimal( $price_without_tax, wc_get_price_decimals() ) ),
			'tax' => $tax_rate,
			'tax_total' => $this->normalize_price( wc_format_decimal( $tax_total, wc_get_price_decimals() ) ),
		);
	}

	/**
	 * Get total tax rate percentage from WooCommerce tax rules.
	 *
	 * @param array $tax_rates WooCommerce tax rates.
	 * @return float
	 */
	private function get_total_tax_rate( $tax_rates ) {
		$rate = 0.0;

		foreach ( $tax_rates as $tax_rate ) {
			if ( isset( $tax_rate['rate'] ) ) {
				$rate += (float) $tax_rate['rate'];
			}
		}

		return $this->normalize_price( wc_format_decimal( $rate, 2 ) );
	}

	/**
	 * Get stock-related fields for a product.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @return array
	 */
	private function get_stock_data( $product ) {
		$manage_stock = (bool) $product->managing_stock();

		return array(
			'in_stock' => (bool) $product->is_in_stock(),
			'stock_status' => $product->get_stock_status(),
			'manage_stock' => $manage_stock,
			'stock_quantity' => $manage_stock ? $product->get_stock_quantity() : null,
			'backorders_allowed' => $product->get_backorders(),
		);
	}
}
