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
	 * Option name controlling whether Miguel's backend sends order emails.
	 */
	const SEND_EMAIL_OPTION = 'miguel_send_order_email';

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * v2 client instance
	 *
	 * @var Miguel_V2_Client
	 */
	private $client;

	/**
	 * Order mapper
	 *
	 * @var Miguel_Order_Mapper
	 */
	private $mapper;

	/**
	 * Constructor with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 * @param Miguel_V2_Client    $client       v2 client instance for order sync.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_V2_Client $client ) {
		$this->hook_manager = $hook_manager;
		$this->client       = $client;
		$this->mapper       = new Miguel_Order_Mapper();
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

		/**
		 * Filters whether the WooCommerce -> Miguel order sync should be skipped.
		 *
		 * Orders created through the Miguel order create API already exist in
		 * Miguel's backend, so syncing them back would create a duplicate order
		 * with a different order code. The create API activates this filter while
		 * it creates the WooCommerce order to suppress that initial sync-back.
		 * Later updates to the order sync as usual, matched by the WooCommerce ID.
		 *
		 * @param bool $suppress Whether to skip queuing the sync. Default false.
		 * @param int  $order_id Order ID being processed.
		 */
		if ( apply_filters( 'miguel_suppress_order_sync', false, $order_id ) ) {
			Miguel::debug_log(
				'Skipped queuing order sync because suppression filter is active',
				array( 'order_id' => $order_id )
			);

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
	 * Whether Miguel's backend should send order emails.
	 *
	 * @return bool
	 */
	private function is_send_order_email_enabled() {
		return 'yes' === get_option( self::SEND_EMAIL_OPTION, 'no' );
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
			$hash_data = array(
				'action'   => 'delete',
				'order_id' => $order->get_id(),
			);
		} else {
			$order_create = $this->mapper->map( $order, $this->is_send_order_email_enabled() );
			$hash_data    = array(
				'action'   => 'sync',
				'order_id' => $order->get_id(),
				'status'   => $order->get_status(),
				'data'     => null === $order_create ? array() : $order_create->to_array(),
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
		if ( 0 == $order->get_id() || in_array( $to_state, array( 'trash', 'refunded', 'cancelled', 'failed' ), true ) ) {
			if ( ! $this->has_order_data_changed( $order, 'delete' ) ) {
				return;
			}

			$result = $this->client->delete_order( strval( $order_id ) );

			if ( is_wp_error( $result ) ) {
				Miguel::log( 'Failed to delete order ' . $order_id . ': ' . $result->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully deleted order ' . $order_id . ' from Miguel API', 'info' );
				$this->store_order_hash( $order, 'delete' );
			}
		} else {
			if ( ! $this->has_order_data_changed( $order, 'sync' ) ) {
				return;
			}

			$order_create = $this->mapper->map( $order, $this->is_send_order_email_enabled() );
			if ( null === $order_create ) {
				return;
			}

			$result = $this->client->create_order( $order_create );

			if ( is_wp_error( $result ) ) {
				Miguel::log( 'Failed to sync order ' . $order_id . ': ' . $result->get_error_message(), 'error' );
			} else {
				Miguel::log( 'Successfully synced order ' . $order_id . ' with Miguel API', 'info' );
				$this->store_order_hash( $order, 'sync' );
			}
		}
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
