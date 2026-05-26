<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API for updating WooCommerce order status with idempotency.
 *
 * @package Miguel
 */
class Miguel_Order_Status_Update_Api {
	use Miguel_Rest_Auth_Trait;

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
			'/orders/(?P<id>\\d+)/status',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'callback' => array( $this, 'update_order_status' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Update order status using WooCommerce native methods.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_order_status( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$order_id = absint( $request->get_param( 'id' ) );
		if ( $order_id <= 0 ) {
			return new WP_Error(
				'order.invalid_id',
				esc_html__( 'Order ID must be a positive integer.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		if ( ! array_key_exists( 'status', $payload ) || '' === trim( (string) $payload['status'] ) ) {
			return new WP_Error(
				'order.status_required',
				esc_html__( 'Order status is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$target_status = $this->normalize_order_status( $payload['status'] );
		if ( '' === $target_status || ! $this->is_supported_request_status( $target_status ) ) {
			return new WP_Error(
				'order.status_invalid',
				esc_html__( 'Order status is not supported by WooCommerce.', 'miguel' ),
				array(
					'status' => 409,
					'order_status' => $payload['status'],
					'allowed_statuses' => $this->get_supported_request_statuses(),
				)
			);
		}

		$idempotency_key = $this->get_idempotency_key( $request );
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

		$normalized_payload = $this->normalize_for_hash(
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
			return $this->build_replay_response( $replay['order_id'] );
		}

		$lock_acquired = add_option( $lock_option, (string) time(), '', 'no' );
		if ( ! $lock_acquired ) {
			$replay_after_lock_fail = $this->read_idempotent_result( $result_option, $payload_hash );
			if ( is_wp_error( $replay_after_lock_fail ) ) {
				return $replay_after_lock_fail;
			}
			if ( is_array( $replay_after_lock_fail ) ) {
				return $this->build_replay_response( $replay_after_lock_fail['order_id'] );
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
				return $this->build_replay_response( $replay_after_lock['order_id'] );
			}

			try {
				if ( 'paid' === $target_status ) {
					$order->payment_complete();
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

			return new WP_REST_Response(
				array(
					'id' => $order_id,
					'status' => $order->get_status(),
					'idempotent_replay' => false,
				),
				200
			);
		} finally {
			delete_option( $lock_option );
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
	 * Build replay response for already-updated order.
	 *
	 * @param int $order_id Order ID.
	 * @return WP_REST_Response
	 */
	private function build_replay_response( $order_id ) {
		$order = wc_get_order( $order_id );

		return new WP_REST_Response(
			array(
				'id' => $order_id,
				'status' => $order ? $order->get_status() : 'unknown',
				'idempotent_replay' => true,
			),
			200
		);
	}

	/**
	 * Normalize payload for deterministic hashing.
	 *
	 * @param mixed $value Data to normalize.
	 * @return mixed
	 */
	private function normalize_for_hash( $value ) {
		if ( is_array( $value ) ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );

			if ( $is_assoc ) {
				ksort( $value );
			}

			foreach ( $value as $key => $nested_value ) {
				$value[ $key ] = $this->normalize_for_hash( $nested_value );
			}
		}

		return $value;
	}

	/**
	 * Extract idempotency key from Idempotency-Key header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private function get_idempotency_key( $request ) {
		$key = (string) $request->get_header( 'idempotency-key' );

		return sanitize_text_field( trim( $key ) );
	}

	/**
	 * Normalize incoming WooCommerce order status.
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	private function normalize_order_status( $status ) {
		$normalized = sanitize_key( trim( (string) $status ) );
		if ( 0 === strpos( $normalized, 'wc-' ) ) {
			$normalized = substr( $normalized, 3 );
		}

		return $normalized;
	}

	/**
	 * Return all supported WooCommerce order statuses without wc- prefix.
	 *
	 * @return array
	 */
	private function get_supported_order_statuses() {
		$statuses = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status_key ) {
			$statuses[] = preg_replace( '/^wc-/', '', (string) $status_key );
		}

		return $statuses;
	}

	/**
	 * Return all supported request statuses including paid pseudo-status.
	 *
	 * @return array
	 */
	private function get_supported_request_statuses() {
		$statuses = $this->get_supported_order_statuses();
		$statuses[] = 'paid';

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Check whether status is supported by WooCommerce.
	 *
	 * @param string $status Normalized order status.
	 * @return bool
	 */
	private function is_supported_request_status( $status ) {
		return in_array( $status, $this->get_supported_request_statuses(), true );
	}
}
