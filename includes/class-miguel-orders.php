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
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager
	 */
	private $hook_manager;

	/**
	 * API instance
	 *
	 * @var Miguel_API
	 */
	private $api;

	/**
	 * Logger instance
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Constructor with dependency injection
	 *
	 * @param Miguel_Hook_Manager $hook_manager Hook manager for registering actions.
	 * @param Miguel_API          $api          API instance for order sync.
	 * @param WC_Logger           $logger       Logger instance for logging.
	 */
	public function __construct( Miguel_Hook_Manager $hook_manager, Miguel_API $api, WC_Logger $logger = null ) {
		$this->hook_manager = $hook_manager;
		$this->api          = $api;
		$this->logger       = $logger;
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order' ), 10, 4 );
		$this->hook_manager->add_action( 'woocommerce_update_order', array( $this, 'handle_order_update' ), 10, 1 );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager
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

			$response = $this->get_api()->delete_order( strval( $order_id ) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Failed to delete order ' . $order_id . ': ' . $response->get_error_message(), 'error' );
			} else {
				$this->log( 'Successfully deleted order ' . $order_id . ' from Miguel API', 'info' );
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

			$response = $this->get_api()->submit_order( $order_data );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Failed to sync order ' . $order_id . ': ' . $response->get_error_message(), 'error' );
			} else {
				$this->log( 'Successfully synced order ' . $order_id . ' with Miguel API', 'info' );
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

	/**
	 * Get API instance with fallback
	 *
	 * @return Miguel_API
	 */
	private function get_api() {
		return $this->api ?: miguel()->api();
	}

	/**
	 * Log message with fallback
	 *
	 * @param string $message Message to log.
	 * @param string $type    Log type (info, error, etc.).
	 */
	private function log( $message, $type = 'info' ) {
		if ( $this->logger ) {
			$this->logger->add( 'miguel', strtoupper( $type ) . ' ' . $message );
		} else {
			Miguel::log( $message, $type );
		}
	}
}
