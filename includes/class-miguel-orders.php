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
	 * Action hook used for asynchronous order synchronization.
	 */
	const ASYNC_SYNC_ACTION = 'miguel_async_sync_order';

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * API instance
	 *
	 * @var Miguel_API
	 */
	private $api;

	/**
	 * Constructor with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 * @param Miguel_API          $api          API instance for order sync.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_API $api ) {
		$this->hook_manager = $hook_manager;
		$this->api          = $api;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_order_status_changed', array( $this, 'queue_order_sync' ), 10, 4 );
		$this->hook_manager->add_action( 'woocommerce_update_order', array( $this, 'queue_order_update_sync' ), 10, 1 );
		$this->hook_manager->add_action( self::ASYNC_SYNC_ACTION, array( $this, 'handle_async_order_sync' ), 10, 3 );
	}

	/**
	 * Queue order synchronization after status change.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from_state Previous status.
	 * @param string   $to_state New status.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function queue_order_sync( $order_id, $from_state, $to_state, $order ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		$args = array(
			'order_id' => $order_id,
			'from_state' => (string) $from_state,
			'to_state' => (string) $to_state,
		);

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ASYNC_SYNC_ACTION, $args, 'miguel' ) ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASYNC_SYNC_ACTION, $args, 'miguel', false );
			return;
		}

		wp_schedule_single_event( time(), self::ASYNC_SYNC_ACTION, array( $order_id, (string) $from_state, (string) $to_state ) );
	}

	/**
	 * Queue order synchronization for generic order updates.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function queue_order_update_sync( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->queue_order_sync( $order_id, '', $order->get_status(), $order );
	}

	/**
	 * Handle asynchronous order sync callback.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from_state Previous status.
	 * @param string $to_state New status.
	 * @return void
	 */
	public function handle_async_order_sync( $order_id, $from_state = '', $to_state = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Miguel::debug_log(
				'Asynchronous order sync skipped because order no longer exists',
				array(
					'order_id' => absint( $order_id ),
				)
			);

			return;
		}

		$this->sync_order( absint( $order_id ), (string) $from_state, (string) $to_state, $order );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager_Interface
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Generate hash of order data for deduplication
	 *
	 * @param WC_Order $order Order object.
	 * @param 'sync' | 'delete' $action Action type (sync or delete).
	 * @return string
	 */
	private function generate_order_hash( $order, $action ) {
		if ( 'delete' === $action ) {
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

			$response = $this->api->delete_order( strval( $order_id ) );

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

			$response = $this->api->submit_order( $order_data );

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
			if ( ! $product ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false );

			// Get Miguel products with calculated prices
			$miguel_products = $this->get_miguel_products_from_item( $product, $item_total );

			$products = array_merge( $products, $miguel_products );
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
	 * Get Miguel products with calculated prices from an order item's product
	 *
	 * @param WC_Product $product Product object.
	 * @param float $item_total Total price of the order item (without VAT).
	 * @return array Array of product data with codes and prices.
	 */
	private function get_miguel_products_from_item( $product, $item_total ) {
		$products = array();

		// Check for bundle products (Melvil WooCommerce Bundle plugin)
		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			$products = $this->get_miguel_products_from_bundle( $bundle_ids, $item_total );
		}

		// Also check if the product itself is downloadable with Miguel codes
		if ( $product->is_downloadable() ) {
			$miguel_codes = $this->extract_miguel_codes_from_product( $product );
			foreach ( $miguel_codes as $code ) {
				$products[] = array(
					'code' => $code,
					'price' => array(
						'sold_without_vat' => $item_total,
					),
				);
			}
		}

		return $products;
	}

	/**
	 * Get Miguel products from a bundle with proportionally calculated prices
	 *
	 * @param array $bundle_ids Array of bundled product IDs.
	 * @param float $bundle_total Total price of the bundle (without VAT).
	 * @return array Array of product data with codes and prices.
	 */
	private function get_miguel_products_from_bundle( $bundle_ids, $bundle_total ) {
		$products = array();
		$bundled_items = array();
		$total_regular_price = 0;

		// First pass: collect all bundled products with Miguel codes and their regular prices
		foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
			$bundled_product = wc_get_product( $bundle_product_id );
			if ( ! $bundled_product ) {
				continue;
			}

			// Recursively get Miguel products (handles nested bundles)
			$nested_codes = $this->extract_all_miguel_codes( $bundled_product );
			if ( empty( $nested_codes ) ) {
				continue;
			}

			$regular_price = floatval( $bundled_product->get_regular_price() );
			if ( $regular_price <= 0 ) {
				$regular_price = floatval( $bundled_product->get_price() );
			}

			$bundled_items[] = array(
				'codes' => $nested_codes,
				'regular_price' => $regular_price,
			);
			$total_regular_price += $regular_price;
		}

		// Second pass: calculate proportional prices and create product entries
		foreach ( $bundled_items as $bundled_item ) {
			// Calculate proportional price based on regular price ratio
			if ( $total_regular_price > 0 ) {
				$price_ratio = $bundled_item['regular_price'] / $total_regular_price;
				$calculated_price = $bundle_total * $price_ratio;
			} else {
				// Fallback: divide equally if no regular prices available
				$calculated_price = $bundle_total / count( $bundled_items );
			}

			foreach ( $bundled_item['codes'] as $code ) {
				$products[] = array(
					'code' => $code,
					'price' => array(
						'sold_without_vat' => round( $calculated_price, 2 ),
					),
				);
			}
		}

		return $products;
	}

	/**
	 * Extract all Miguel codes from a product (including nested bundles)
	 *
	 * @param WC_Product $product Product object.
	 * @return array Array of unique Miguel codes.
	 */
	private function extract_all_miguel_codes( $product ) {
		$miguel_codes = array();

		// Check for nested bundles
		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
				$bundled_product = wc_get_product( $bundle_product_id );
				if ( $bundled_product ) {
					$nested_codes = $this->extract_all_miguel_codes( $bundled_product );
					foreach ( $nested_codes as $code ) {
						if ( ! in_array( $code, $miguel_codes, true ) ) {
							$miguel_codes[] = $code;
						}
					}
				}
			}
		}

		// Extract codes from this product's downloads
		if ( $product->is_downloadable() ) {
			$codes = $this->extract_miguel_codes_from_product( $product );
			foreach ( $codes as $code ) {
				if ( ! in_array( $code, $miguel_codes, true ) ) {
					$miguel_codes[] = $code;
				}
			}
		}

		return $miguel_codes;
	}

	/**
	 * Extract Miguel codes from a product's downloadable files
	 *
	 * @param WC_Product $product Product object.
	 * @return array Array of unique Miguel codes.
	 */
	private function extract_miguel_codes_from_product( $product ) {
		$miguel_codes = array();

		$downloads = $product->get_downloads();
		foreach ( $downloads as $download ) {
			if ( Miguel_Order_Utils::is_miguel_shortcode( $download['file'] ) ) {
				$code = Miguel_Order_Utils::extract_miguel_code( $download['file'] );
				if ( $code && ! in_array( $code, $miguel_codes, true ) ) {
					$miguel_codes[] = $code;
				}
			}
		}

		return $miguel_codes;
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
