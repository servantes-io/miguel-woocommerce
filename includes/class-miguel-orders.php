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
		// Hook into order status changes - this covers all status transitions including new orders
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order' ), 10, 4 );
		// Hook into order updates (for item changes)
		add_action( 'woocommerce_update_order', array( $this, 'handle_order_update' ), 10, 1 );
	}

	public function deconstruct() {
		remove_action( 'woocommerce_order_status_changed', array( $this, 'sync_order' ), 10 );
		remove_action( 'woocommerce_update_order', array( $this, 'handle_order_update' ), 10 );
	}

	/**
	 * Generate hash of order data for deduplication
	 *
	 * @param WC_Order $order Order object.
	 * @param 'sync' | 'delete' $action Action type (sync or delete).
	 * @return string
	 */
	private function generate_order_hash( $order, $action ) {
		if ( $action === 'delete' ) {
			// For deletions, just use order ID and action
			$hash_data = array(
				'action' => 'delete',
				'order_id' => $order->get_id(),
			);
		} else {
			// For sync operations, include all relevant order data
			$order_data = $this->prepare_order_data( $order );
			$hash_data = array(
				'action' => 'sync',
				'order_id' => $order->get_id(),
				'status' => $order->get_status(),
				'data' => $order_data,
			);
		}

		return md5( wp_json_encode( $hash_data ) );
	}

	/**
	 * Check if order data has changed since last sync
	 *
	 * @param WC_Order $order Order object.
	 * @param 'sync' | 'delete' $action Action type (sync or delete).
	 * @return bool True if data has changed or no previous hash exists.
	 */
	private function has_order_data_changed( $order, $action ) {
		$current_hash = $this->generate_order_hash( $order, $action );
		$stored_hash = $order->get_meta( '_miguel_last_sync_hash', true );

		return $stored_hash !== $current_hash;
	}

	/**
	 * Store the current order data hash
	 *
	 * @param WC_Order $order Order object.
	 * @param 'sync' | 'delete' $action Action type (sync or delete).
	 */
	private function store_order_hash( $order, $action ) {
		$current_hash = $this->generate_order_hash( $order, $action );
		$order->update_meta_data( '_miguel_last_sync_hash', $current_hash );
		$order->save_meta_data();
	}

	/**
	 * Sync order with Miguel API
	 *
	 * @param int $order_id Order ID.
	 * @param string $from_state Previous status.
	 * @param string $to_state New status.
	 * @param WC_Order $order Order object.
	 */
	public function sync_order( $order_id, $from_state, $to_state, $order ) {
		if ( $order->get_id() == 0 || in_array( $to_state, array( 'trash', 'refunded', 'cancelled', 'failed' ) ) ) {
			// Check if we need to delete (avoid duplicate delete requests)
			if ( ! $this->has_order_data_changed( $order, 'delete' ) ) {
				return;
			}

			$response = miguel()->api()->delete_order( strval( $order_id ) );

			if ( is_wp_error( $response ) ) {
				Miguel::log( 'Failed to delete order ' . $order_id . ': ' . $response->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully deleted order ' . $order_id . ' from Miguel API', 'info' );
				$this->store_order_hash( $order, 'delete' );
			}
		} else {
			// Check if order data has changed (avoid duplicate sync requests)
			if ( ! $this->has_order_data_changed( $order, 'sync' ) ) {
				return;
			}

			$order_data = $this->prepare_order_data( $order );
			if ( empty( $order_data ) ) {
				return;
			}

			$response = miguel()->api()->submit_order( $order_data );

			if ( is_wp_error( $response ) ) {
				Miguel::log( 'Failed to sync order ' . $order_id . ': ' . $response->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully synced order ' . $order_id . ' with Miguel API', 'info' );
				$this->store_order_hash( $order, 'sync' );
			}
		}
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
				if ( Miguel_Order_Utils::is_miguel_shortcode( $download['file'] ) ) {
					$code = Miguel_Order_Utils::extract_miguel_code( $download['file'] );
					if ( $code && ! in_array( $code, $miguel_codes ) ) {
						$miguel_codes[] = $code;
					}
				}
			}

			// Create separate product item for each unique code
			foreach ( $miguel_codes as $code ) {
				$products[] = array(
					'code' => $code,
					'price' => array(
						'sold_without_vat' => $order->get_item_total( $item, false, false ),
					),
				);
			}
		}

		if ( empty( $products ) ) {
			return array();
		}

		return array(
			'code' => strval( $order->get_id() ),
			'eshop_id' => strval( $order->get_id() ),
			'user' => Miguel_Order_Utils::get_user_data_for_order( $order, true ),
			'products' => $products,
			'currency_code' => $order->get_currency(),
			'purchase_date' => Miguel_Order_Utils::get_purchase_date_for_order( $order ),
			'send_email' => 'disable',
		);
	}

	/**
	 * Handle order updates (when items are added/removed)
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_update( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Re-sync the order with updated data
		$this->sync_order( $order_id, '', $order->get_status(), $order );
	}
}

return new Miguel_Orders();
