<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Public REST API for creating WooCommerce orders with idempotency.
 *
 * @package Miguel
 */
class Miguel_Order_Create_Api {

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
			'/orders',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'create_order' ),
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
	 * Create order via official WooCommerce REST controller with idempotency.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_order( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$idempotency_key = $this->get_idempotency_key( $request, $payload );
		if ( '' === $idempotency_key ) {
			return new WP_Error(
				'idempotency_key.required',
				esc_html__( 'Idempotency key is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$normalized_payload = $this->normalize_for_hash( $payload );
		$payload_hash = hash( 'sha256', wp_json_encode( $normalized_payload ) );
		$key_hash = hash( 'sha256', $idempotency_key );

		$result_option = 'miguel_order_idem_result_' . $key_hash;
		$lock_option = 'miguel_order_idem_lock_' . $key_hash;

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

			$response = $this->create_order_via_wc_controller( $payload );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$order_id = $this->extract_order_id( $response );
			if ( $order_id <= 0 ) {
				return new WP_Error(
					'order.create_failed',
					esc_html__( 'Order was not created.', 'miguel' ),
					array( 'status' => 500 )
				);
			}

			update_option(
				$result_option,
				array(
					'order_id' => $order_id,
					'payload_hash' => $payload_hash,
					'created_at' => gmdate( 'c' ),
				),
				'no'
			);

			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_miguel_idempotency_key', $idempotency_key );
				$order->save_meta_data();
			}

			$data = $this->extract_response_data( $response );
			$data['idempotent_replay'] = false;

			return new WP_REST_Response( $data, 201 );
		} finally {
			delete_option( $lock_option );
		}
	}

	/**
	 * Create order using official WooCommerce REST Orders controller.
	 *
	 * @param array $payload Request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_order_via_wc_controller( $payload ) {
		if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
			return new WP_Error(
				'wc_rest_unavailable',
				esc_html__( 'WooCommerce REST Orders controller is unavailable.', 'miguel' ),
				array( 'status' => 500 )
			);
		}

		$controller = new WC_REST_Orders_Controller();
		$wc_request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
		$wc_request->set_body_params( $payload );
		if ( method_exists( $wc_request, 'set_json_params' ) ) {
			$wc_request->set_json_params( $payload );
		}

		return $controller->create_item( $wc_request );
	}

	/**
	 * Return idempotent result if already created.
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
	 * Build replay response for existing order.
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
	 * Extract order ID from WC REST response.
	 *
	 * @param WP_REST_Response $response Controller response.
	 * @return int
	 */
	private function extract_order_id( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return 0;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return 0;
		}

		return absint( $data['id'] );
	}

	/**
	 * Extract response data from WC response.
	 *
	 * @param WP_REST_Response $response Controller response.
	 * @return array
	 */
	private function extract_response_data( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return array();
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Extract idempotency key from header/body.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param array $payload Body payload.
	 * @return string
	 */
	private function get_idempotency_key( $request, $payload ) {
		$key = (string) $request->get_header( 'idempotency-key' );

		if ( '' === $key ) {
			$key = (string) $request->get_header( 'x-idempotency-key' );
		}

		if ( '' === $key && isset( $payload['idempotency_key'] ) ) {
			$key = (string) $payload['idempotency_key'];
		}

		return sanitize_text_field( trim( $key ) );
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
}
