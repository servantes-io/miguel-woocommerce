<?php
/**
 * Order synchronization handler
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Order synchronization handler
 *
 * @package Miguel
 */
class Miguel_Orders {

	/**
	 * Add WooCommerce hooks.
	 */
	public function __construct() {
		// Hook into order status changes
		add_action( 'woocommerce_new_order', array( $this, 'sync_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'sync_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'sync_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'sync_order' ), 10, 1 );
	}

	/**
	 * Sync order with Miguel API
	 *
	 * @param int $order_id Order ID.
	 */
	public function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Miguel::log( 'Invalid order ID: ' . $order_id, 'error' );
			return;
		}

		// Only sync orders that have Miguel products
		if ( ! $this->has_miguel_products( $order ) ) {
			return;
		}

		// Prepare order data for Miguel API
		$order_data = $this->prepare_order_data( $order );
		if ( empty( $order_data ) ) {
			return;
		}

		// Send order to Miguel API
		$response = miguel()->api()->submit_order( $order_data );

		if ( is_wp_error( $response ) ) {
			Miguel::log( 'Failed to sync order ' . $order_id . ': ' . $response->get_error_message(), 'error' );
		} else {
			Miguel::log( 'Successfully synced order ' . $order_id . ' with Miguel API', 'info' );
		}
	}

	/**
	 * Check if order contains Miguel products
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function has_miguel_products( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->is_downloadable() ) {
				continue;
			}

			// Check if any downloadable file contains a Miguel shortcode
			$downloads = $product->get_downloads();
			foreach ( $downloads as $download ) {
				if ( $this->is_miguel_shortcode( $download['file'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if file URL is a Miguel shortcode
	 *
	 * @param string $file_url File URL.
	 * @return bool
	 */
	private function is_miguel_shortcode( $file_url ) {
		return strpos( $file_url, '[miguel ' ) === 0;
	}

	/**
	 * Prepare order data for Miguel API
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function prepare_order_data( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->is_downloadable() ) {
				continue;
			}

			// Get Miguel product codes from downloadable files
			$downloads = $product->get_downloads();
			$miguel_codes = array();

			foreach ( $downloads as $download ) {
				if ( $this->is_miguel_shortcode( $download['file'] ) ) {
					$code = $this->extract_miguel_code( $download['file'] );
					if ( $code ) {
						$miguel_codes[] = $code;
					}
				}
			}

			if ( ! empty( $miguel_codes ) ) {
				$products[] = array(
					'codes' => $miguel_codes,
					'quantity' => $item->get_quantity(),
					'unit_price' => $order->get_item_total( $item, false, false ),
				);
			}
		}

		if ( empty( $products ) ) {
			return array();
		}

		return array(
			'code' => strval( $order->get_id() ),
			'user' => Miguel_Order_Utils::get_user_data_for_order( $order, true ), // Use enhanced address format
			'products' => $products,
			'currency_code' => $order->get_currency(),
			'purchase_date' => Miguel_Order_Utils::get_purchase_date_for_order( $order ),
		);
	}

	/**
	 * Extract Miguel code from shortcode
	 *
	 * @param string $shortcode Shortcode string.
	 * @return string|null
	 */
	private function extract_miguel_code( $shortcode ) {
		// Check if it's a Miguel shortcode first
		if ( ! $this->is_miguel_shortcode( $shortcode ) ) {
			return null;
		}

		// Use the existing helper function to parse shortcode attributes
		$atts = miguel_get_shortcode_atts( $shortcode );

		// Check for 'id' attribute (current format)
		if ( ! empty( $atts['id'] ) ) {
			return $atts['id'];
		}

		// Check for 'book' attribute (legacy format)
		if ( ! empty( $atts['book'] ) ) {
			return $atts['book'];
		}

		return null;
	}
}

return new Miguel_Orders();
