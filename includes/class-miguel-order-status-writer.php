<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Idempotent WooCommerce order-status application.
 *
 * The single place either Miguel route changes an order's status, so the two cannot
 * drift apart on lock and replay semantics.
 *
 * @package Miguel
 */
class Miguel_Order_Status_Writer {

	/**
	 * Apply a target status to an order, idempotently.
	 *
	 * @param int    $order_id        WooCommerce order id.
	 * @param string $target_status   Normalized status slug, or the 'paid' pseudo-status.
	 * @param string $idempotency_key Caller-supplied idempotency key.
	 * @return array|WP_Error array( order_id, status, idempotent_replay ) on success.
	 */
	public function apply( $order_id, $target_status, $idempotency_key ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return new WP_Error(
				'order.invalid_id',
				esc_html__( 'Order ID must be a positive integer.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$idempotency_key = (string) $idempotency_key;
		if ( '' === $idempotency_key ) {
			return new WP_Error(
				'idempotency.key_required',
				esc_html__( 'Idempotency-Key header is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order.not_found',
				esc_html__( 'Order was not found.', 'miguel' ),
				array( 'status' => 404 )
			);
		}

		$normalized_payload = self::normalize_for_hash(
			array(
				'order_id' => $order_id,
				'status' => $target_status,
			)
		);
		$payload_hash = hash( 'sha256', wp_json_encode( $normalized_payload ) );
		$key_hash = hash( 'sha256', $idempotency_key );

		$result_option = 'miguel_order_status_idem_result_' . $key_hash;
		$lock_option = 'miguel_order_status_idem_lock_' . $key_hash;

		$replay = $this->read_idempotent_result( $result_option, $payload_hash );
		if ( is_wp_error( $replay ) ) {
			return $replay;
		}
		if ( is_array( $replay ) ) {
			return $this->build_replay_result( $replay['order_id'] );
		}

		$lock_acquired = add_option( $lock_option, (string) time(), '', 'no' );
		if ( ! $lock_acquired ) {
			$replay_after_lock_fail = $this->read_idempotent_result( $result_option, $payload_hash );
			if ( is_wp_error( $replay_after_lock_fail ) ) {
				return $replay_after_lock_fail;
			}
			if ( is_array( $replay_after_lock_fail ) ) {
				return $this->build_replay_result( $replay_after_lock_fail['order_id'] );
			}

			return new WP_Error(
				'idempotency.in_progress',
				esc_html__( 'Request with this idempotency key is currently being processed.', 'miguel' ),
				array( 'status' => 409 )
			);
		}

		try {
			$replay_after_lock = $this->read_idempotent_result( $result_option, $payload_hash );
			if ( is_wp_error( $replay_after_lock ) ) {
				return $replay_after_lock;
			}
			if ( is_array( $replay_after_lock ) ) {
				return $this->build_replay_result( $replay_after_lock['order_id'] );
			}

			try {
				if ( 'paid' === $target_status ) {
					$this->complete_payment( $order );
				} else {
					$order->update_status( $target_status, '', true );
				}
			} catch ( Exception $exception ) {
				return new WP_Error(
					'order.status_update_failed',
					esc_html__( 'Order status could not be updated.', 'miguel' ),
					array(
						'status' => 500,
						'order_id' => $order_id,
						'order_status' => $target_status,
						'error_message' => $exception->getMessage(),
					)
				);
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_Error(
					'order.not_found',
					esc_html__( 'Order was not found.', 'miguel' ),
					array( 'status' => 404 )
				);
			}

			if ( 'paid' === $target_status && ! $order->is_paid() ) {
				return new WP_Error(
					'order.status_update_failed',
					esc_html__( 'Order could not be marked as paid.', 'miguel' ),
					array(
						'status' => 500,
						'order_id' => $order_id,
						'order_status' => $target_status,
						'current_status' => $order->get_status(),
					)
				);
			}

			if ( 'paid' !== $target_status && $target_status !== $order->get_status() ) {
				return new WP_Error(
					'order.status_update_failed',
					esc_html__( 'Order status could not be updated.', 'miguel' ),
					array(
						'status' => 500,
						'order_id' => $order_id,
						'order_status' => $target_status,
						'current_status' => $order->get_status(),
					)
				);
			}

			update_option(
				$result_option,
				array(
					'order_id' => $order_id,
					'payload_hash' => $payload_hash,
					'status' => $order->get_status(),
					'updated_at' => gmdate( 'c' ),
				),
				'no'
			);

			return array(
				'order_id' => $order_id,
				'status' => $order->get_status(),
				'idempotent_replay' => false,
			);
		} finally {
			delete_option( $lock_option );
		}
	}

	/**
	 * Run WooCommerce's payment_complete() so it actually completes the payment.
	 *
	 * WC_Order::payment_complete() acts only on on-hold, pending, failed and cancelled
	 * (`woocommerce_valid_order_statuses_for_payment_complete`). From anything else — typically a
	 * payment gateway's own status, such as "awaiting" — it takes the else branch, changes nothing,
	 * and still returns true. The order stays unpaid and the caller's verification then fails.
	 *
	 * Miguel only asks for this when it knows the payment succeeded, so the order's current status
	 * is added to that list for this one call. The filter is scoped to this order and removed
	 * immediately, so no other gateway's payment flow is affected.
	 *
	 * Two statuses are deliberately not added: an already-paid order needs no completing, and a
	 * refunded one must not be quietly resurrected. Cancelled is left to WooCommerce, which allows
	 * it by default.
	 *
	 * @param WC_Order $order Order to complete payment for.
	 */
	private function complete_payment( $order ) {
		$allow_current_status = function ( $statuses, $filtered_order ) use ( $order ) {
			if ( ! $filtered_order instanceof WC_Order
				|| $filtered_order->get_id() !== $order->get_id() ) {
				return $statuses;
			}

			$current = $filtered_order->get_status();
			if ( in_array( $current, $statuses, true )
				|| 'refunded' === $current
				|| $filtered_order->is_paid() ) {
				return $statuses;
			}

			$statuses[] = $current;

			return $statuses;
		};

		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow_current_status, 10, 2 );

		try {
			$order->payment_complete();
		} finally {
			remove_filter( 'woocommerce_valid_order_statuses_for_payment_complete', $allow_current_status, 10 );
		}
	}

	/**
	 * Return idempotent result if already updated.
	 *
	 * @param string $result_option Option name with result.
	 * @param string $payload_hash Current payload hash.
	 * @return array|false|WP_Error
	 */
	private function read_idempotent_result( $result_option, $payload_hash ) {
		$stored = get_option( $result_option, false );
		if ( ! is_array( $stored ) || empty( $stored['order_id'] ) ) {
			return false;
		}

		$stored_hash = isset( $stored['payload_hash'] ) ? (string) $stored['payload_hash'] : '';
		if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $payload_hash ) ) {
			return new WP_Error(
				'idempotency.payload_mismatch',
				esc_html__( 'Idempotency key was already used with different payload.', 'miguel' ),
				array( 'status' => 409 )
			);
		}

		$order_id = absint( $stored['order_id'] );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			delete_option( $result_option );
			return false;
		}

		return array(
			'order_id' => $order_id,
		);
	}

	/**
	 * Build the replay result for an already-updated order.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	private function build_replay_result( $order_id ) {
		$order = wc_get_order( $order_id );

		return array(
			'order_id' => $order_id,
			'status' => $order ? $order->get_status() : 'unknown',
			'idempotent_replay' => true,
		);
	}

	/**
	 * Normalize payload for deterministic hashing.
	 *
	 * @param mixed $value Data to normalize.
	 * @return mixed
	 */
	private static function normalize_for_hash( $value ) {
		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );

			if ( $is_assoc ) {
				ksort( $value );
			}

			foreach ( $value as $key => $nested_value ) {
				$value[ $key ] = self::normalize_for_hash( $nested_value );
			}
		}

		return $value;
	}

	/**
	 * Extract the idempotency key from the Idempotency-Key header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	public static function read_idempotency_key( $request ) {
		$key = (string) $request->get_header( 'idempotency-key' );

		return sanitize_text_field( trim( $key ) );
	}

	/**
	 * Normalize an incoming WooCommerce order status.
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	public static function normalize_order_status( $status ) {
		$normalized = sanitize_key( trim( (string) $status ) );
		if ( 0 === strpos( $normalized, 'wc-' ) ) {
			$normalized = substr( $normalized, 3 );
		}

		return $normalized;
	}

	/**
	 * Registered WooCommerce order statuses, slug => label, without the wc- prefix.
	 *
	 * @return array
	 */
	public static function get_order_statuses() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $status_key => $label ) {
			$statuses[ preg_replace( '/^wc-/', '', (string) $status_key ) ] = $label;
		}

		return $statuses;
	}

	/**
	 * True when the status is a real WooCommerce order status (excludes the paid pseudo-status).
	 *
	 * @param string $status Normalized order status.
	 * @return bool
	 */
	public static function is_supported_order_status( $status ) {
		return array_key_exists( $status, self::get_order_statuses() );
	}

	/**
	 * All statuses accepted on the wire, including the paid pseudo-status.
	 *
	 * @return array
	 */
	public static function get_supported_request_statuses() {
		$statuses = array_keys( self::get_order_statuses() );
		$statuses[] = 'paid';

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Check whether the status is accepted on the wire.
	 *
	 * @param string $status Normalized order status.
	 * @return bool
	 */
	public static function is_supported_request_status( $status ) {
		return in_array( $status, self::get_supported_request_statuses(), true );
	}
}
