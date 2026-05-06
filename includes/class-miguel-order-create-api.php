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
	use Miguel_Rest_Auth_Trait;

	/**
	 * Hook manager instance.
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private $hook_manager;

	/**
	 * Product code resolver.
	 *
	 * @var Miguel_Product_Code_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager.
	 * @param Miguel_Product_Code_Resolver|null $resolver Product code resolver.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, $resolver = null ) {
		$this->hook_manager = $hook_manager;
		$this->resolver = $resolver instanceof Miguel_Product_Code_Resolver ? $resolver : new Miguel_Product_Code_Resolver();
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

		$email_template = $this->get_requested_email_template( $payload );
		$send_emails = $this->should_send_order_emails( $payload );

		Miguel::debug_log(
			'Received order create payload',
			array(
				'idempotency_key' => isset( $payload['idempotency_key'] ) ? $payload['idempotency_key'] : '',
				'line_items_count' => isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ? count( $payload['line_items'] ) : 0,
				'payload' => $payload,
			)
		);

		$top_level_validation = $this->validate_required_order_fields( $payload );
		if ( is_wp_error( $top_level_validation ) ) {
			return $top_level_validation;
		}

		$idempotency_key = $this->get_idempotency_key( $request, $payload );
		if ( '' === $idempotency_key ) {
			return new WP_Error(
				'idempotency.key_required',
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

			$payload = $this->prepare_payload_for_wc_order( $payload );
			if ( is_wp_error( $payload ) ) {
				Miguel::debug_log(
					'Order create aborted during payload preparation',
					array(
						'error_code' => $payload->get_error_code(),
						'error_message' => $payload->get_error_message(),
						'error_data' => $payload->get_error_data(),
					)
				);

				return $payload;
			}

			Miguel::debug_log(
				'Prepared WooCommerce order payload',
				array(
					'payload' => $payload,
				)
			);

			$response = $this->create_order_via_wc_controller( $payload );
			if ( is_wp_error( $response ) ) {
				Miguel::debug_log(
					'WooCommerce order controller returned error',
					array(
						'error_code' => $response->get_error_code(),
						'error_message' => $response->get_error_message(),
						'error_data' => $response->get_error_data(),
						'payload' => $payload,
					)
				);
				return $response;
			}

			$order_id = $this->extract_order_id( $response );
			if ( $order_id <= 0 ) {
				return new WP_Error(
					'order.creation_failed',
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

			$emails_dispatched = false;
			if ( $send_emails && $order ) {
				$emails_dispatched = $this->dispatch_order_emails( $order, $email_template );
			}

			$data = $this->extract_response_data( $response );
			$data['idempotent_replay'] = false;
			$data['debug_log_path'] = Miguel::get_debug_log_path();
			$data['emails_requested'] = $send_emails;
			$data['emails_dispatched'] = $emails_dispatched;
			$data['email_template'] = $email_template;

			Miguel::debug_log(
				'WooCommerce order created successfully',
				array(
					'order_id' => $order_id,
					'idempotency_key' => $idempotency_key,
					'emails_requested' => $send_emails,
					'emails_dispatched' => $emails_dispatched,
					'email_template' => $email_template,
					'response_payload' => $data,
				)
			);

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
				'order.rest_unavailable',
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
	 * Resolve productCode values in line items before passing payload to WooCommerce.
	 *
	 * @param array $payload Request body.
	 * @return array|WP_Error
	 */
	private function prepare_payload_for_wc_order( $payload ) {
		unset( $payload['send_emails'], $payload['send_email'], $payload['email_template'] );

		if ( ! array_key_exists( 'line_items', $payload ) ) {
			return $this->build_line_item_error(
				'line_items.required',
				esc_html__( 'Order line_items array is required.', 'miguel' ),
				array()
			);
		}

		if ( ! is_array( $payload['line_items'] ) ) {
			return $this->build_line_item_error(
				'line_items.invalid_structure',
				esc_html__( 'Order line_items must be an array.', 'miguel' ),
				array(
					'line_items' => $payload['line_items'],
				)
			);
		}

		if ( empty( $payload['line_items'] ) ) {
			return $this->build_line_item_error(
				'line_items.empty',
				esc_html__( 'Order must contain at least one line item.', 'miguel' ),
				array()
			);
		}

		foreach ( $payload['line_items'] as $index => $line_item ) {
			$prepared_line_item = $this->prepare_line_item_for_wc_order( $line_item, $index );
			if ( is_wp_error( $prepared_line_item ) ) {
				return $prepared_line_item;
			}

			$payload['line_items'][ $index ] = $prepared_line_item;
		}

		return $payload;
	}

	/**
	 * Prepare one line item for WooCommerce order creation.
	 *
	 * @param mixed $line_item Line item payload.
	 * @param int   $index Line item index.
	 * @return array|mixed|WP_Error
	 */
	private function prepare_line_item_for_wc_order( $line_item, $index ) {
		if ( ! is_array( $line_item ) ) {
			return $this->build_line_item_error(
				'line_item.invalid_structure',
				esc_html__( 'Each line item must be an object.', 'miguel' ),
				array(
					'line_item_index' => $index,
					'line_item' => $line_item,
				)
			);
		}

		if ( ! array_key_exists( 'quantity', $line_item ) ) {
			return $this->build_line_item_error(
				'line_item.quantity_required',
				esc_html__( 'Line item quantity is required.', 'miguel' ),
				array(
					'line_item_index' => $index,
					'line_item' => $line_item,
				)
			);
		}

		if ( ! $this->is_valid_positive_integer_quantity( $line_item['quantity'] ) ) {
			return $this->build_line_item_error(
				'line_item.invalid_quantity',
				esc_html__( 'Line item quantity must be a positive integer.', 'miguel' ),
				array(
					'line_item_index' => $index,
					'quantity' => $line_item['quantity'],
					'line_item' => $line_item,
				)
			);
		}

		$original_line_item = $line_item;
		$product_code = $this->get_line_item_product_code( $line_item );
		$product_id = isset( $line_item['product_id'] ) ? absint( $line_item['product_id'] ) : 0;

		if ( $product_id > 0 ) {
			if ( '' !== $product_code ) {
				$resolved_product = $this->resolver->resolve_product_code( $product_code );
				if ( is_wp_error( $resolved_product ) ) {
					Miguel::debug_log(
						'Failed to resolve line item productCode while validating product reference conflict',
						array(
							'line_item_index' => $index,
							'product_code' => $product_code,
							'product_id' => $product_id,
							'error_code' => $resolved_product->get_error_code(),
							'error_message' => $resolved_product->get_error_message(),
							'error_data' => $resolved_product->get_error_data(),
						)
					);

					return $resolved_product;
				}

				if ( (int) $resolved_product['product_id'] !== $product_id ) {
					return $this->build_line_item_error(
						'line_item.product_reference_conflict',
						esc_html__( 'Line item product_id and product_code refer to different products.', 'miguel' ),
						array(
							'line_item_index' => $index,
							'product_id' => $product_id,
							'product_code' => $product_code,
							'resolved_product_id' => $resolved_product['product_id'],
							'candidate_ids' => $resolved_product['product_ids'],
							'line_item' => $original_line_item,
						)
					);
				}

				Miguel::debug_log(
					'Line item contains matching product_id and product_code, keeping provided ID',
					array(
						'line_item_index' => $index,
						'product_code' => $product_code,
						'product_id' => $product_id,
						'resolved_product_id' => $resolved_product['product_id'],
					)
				);
			}

			unset( $line_item['productCode'], $line_item['product_code'] );
			return $line_item;
		}

		if ( '' === $product_code ) {
			return $this->build_line_item_error(
				'line_item.product_reference_required',
				esc_html__( 'Line item must include product_id or product_code.', 'miguel' ),
				array(
					'line_item_index' => $index,
					'line_item' => $line_item,
				)
			);
		}

		$resolved_product = $this->resolver->resolve_product_code( $product_code );
		if ( is_wp_error( $resolved_product ) ) {
			Miguel::debug_log(
				'Failed to resolve line item productCode',
				array(
					'line_item_index' => $index,
					'product_code' => $product_code,
					'error_code' => $resolved_product->get_error_code(),
					'error_message' => $resolved_product->get_error_message(),
					'error_data' => $resolved_product->get_error_data(),
				)
			);

			return $resolved_product;
		}

		$line_item['product_id'] = $resolved_product['product_id'];
		unset( $line_item['productCode'], $line_item['product_code'] );

		Miguel::debug_log(
			'Resolved line item productCode to product_id',
			array(
				'line_item_index' => $index,
				'original_line_item' => $original_line_item,
				'product_code' => $product_code,
				'product_id' => $resolved_product['product_id'],
				'candidate_ids' => $resolved_product['product_ids'],
				'is_unique' => $resolved_product['is_unique'],
				'prepared_line_item' => $line_item,
			)
		);

		return $line_item;
	}

	/**
	 * Build a normalized line item validation error and log it.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $data Error data.
	 * @return WP_Error
	 */
	private function build_line_item_error( $code, $message, $data ) {
		$error = new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => 409,
				),
				is_array( $data ) ? $data : array()
			)
		);

		Miguel::debug_log(
			'Order payload validation failed',
			array(
				'error_code' => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
				'error_data' => $error->get_error_data(),
			)
		);

		return $error;
	}

	/**
	 * Validate required top-level fields for order creation payload.
	 *
	 * @param array $payload Request payload.
	 * @return true|WP_Error
	 */
	private function validate_required_order_fields( $payload ) {
		$email_template = $this->get_requested_email_template( $payload );
		if ( null !== $email_template && ! in_array( $email_template, $this->get_supported_email_templates(), true ) ) {
			return $this->build_order_payload_error(
				'order.email_template_invalid',
				esc_html__( 'Order email_template must be one of the supported WooCommerce email template identifiers.', 'miguel' ),
				array(
					'field' => 'email_template',
					'email_template' => $email_template,
					'allowed_values' => $this->get_supported_email_templates(),
				)
			);
		}

		if ( array_key_exists( 'customer_id', $payload ) ) {
			$customer_id = absint( $payload['customer_id'] );
			if ( $customer_id <= 0 || ! get_user_by( 'id', $customer_id ) ) {
				return $this->build_order_payload_error(
					'order.customer_id_invalid',
					esc_html__( 'Order customer_id must reference an existing user.', 'miguel' ),
					array( 'field' => 'customer_id', 'customer_id' => $payload['customer_id'] )
				);
			}
		}

		if ( ! array_key_exists( 'payment_method', $payload ) || '' === trim( (string) $payload['payment_method'] ) ) {
			return $this->build_order_payload_error(
				'order.payment_method_required',
				esc_html__( 'Order payment_method is required.', 'miguel' ),
				array( 'field' => 'payment_method' )
			);
		}

		if ( ! array_key_exists( 'billing', $payload ) || ! is_array( $payload['billing'] ) || empty( $payload['billing'] ) ) {
			return $this->build_order_payload_error(
				'order.billing_required',
				esc_html__( 'Order billing object is required.', 'miguel' ),
				array( 'field' => 'billing' )
			);
		}

		if ( ! array_key_exists( 'shipping', $payload ) || ! is_array( $payload['shipping'] ) || empty( $payload['shipping'] ) ) {
			return $this->build_order_payload_error(
				'order.shipping_required',
				esc_html__( 'Order shipping object is required.', 'miguel' ),
				array( 'field' => 'shipping' )
			);
		}

		if ( ! array_key_exists( 'shipping_lines', $payload ) || ! is_array( $payload['shipping_lines'] ) || empty( $payload['shipping_lines'] ) ) {
			return $this->build_order_payload_error(
				'order.shipping_lines_required',
				esc_html__( 'Order shipping_lines array is required.', 'miguel' ),
				array( 'field' => 'shipping_lines' )
			);
		}

		return true;
	}

	/**
	 * Build a normalized top-level order payload error and log it.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $data Error data.
	 * @return WP_Error
	 */
	private function build_order_payload_error( $code, $message, $data ) {
		$error = new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => 409,
				),
				is_array( $data ) ? $data : array()
			)
		);

		Miguel::debug_log(
			'Order top-level payload validation failed',
			array(
				'error_code' => $error->get_error_code(),
				'error_message' => $error->get_error_message(),
				'error_data' => $error->get_error_data(),
			)
		);

		return $error;
	}

	/**
	 * Determine whether quantity is a positive integer value.
	 *
	 * @param mixed $quantity Line item quantity.
	 * @return bool
	 */
	private function is_valid_positive_integer_quantity( $quantity ) {
		if ( is_int( $quantity ) ) {
			return $quantity > 0;
		}

		if ( is_string( $quantity ) ) {
			$quantity = trim( $quantity );
			return '' !== $quantity && preg_match( '/^\d+$/', $quantity ) && intval( $quantity ) > 0;
		}

		return false;
	}

	/**
	 * Extract supported product code key from line item.
	 *
	 * @param array $line_item Line item payload.
	 * @return string
	 */
	private function get_line_item_product_code( $line_item ) {
		if ( isset( $line_item['productCode'] ) ) {
			return sanitize_text_field( trim( (string) $line_item['productCode'] ) );
		}

		if ( isset( $line_item['product_code'] ) ) {
			return sanitize_text_field( trim( (string) $line_item['product_code'] ) );
		}

		return '';
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
	 * Determine whether order emails should be sent after creation.
	 *
	 * @param array $payload Request payload.
	 * @return bool
	 */
	private function should_send_order_emails( $payload ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		if ( null !== $this->get_requested_email_template( $payload ) ) {
			return true;
		}

		if ( array_key_exists( 'send_emails', $payload ) ) {
			return $this->normalize_boolean_flag( $payload['send_emails'] );
		}

		if ( array_key_exists( 'send_email', $payload ) ) {
			return $this->normalize_boolean_flag( $payload['send_email'] );
		}

		return false;
	}

	/**
	 * Extract requested email template identifier from the payload.
	 *
	 * @param array $payload Request payload.
	 * @return string|null
	 */
	private function get_requested_email_template( $payload ) {
		if ( ! is_array( $payload ) || ! array_key_exists( 'email_template', $payload ) ) {
			return null;
		}

		$template = sanitize_key( trim( (string) $payload['email_template'] ) );

		return '' === $template ? null : $template;
	}

	/**
	 * Return the list of supported explicit email templates.
	 *
	 * @return array
	 */
	private function get_supported_email_templates() {
		return array(
			'new_order',
			'customer_invoice',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_failed_order',
		);
	}

	/**
	 * Normalize an incoming boolean-like value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function normalize_boolean_flag( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return 1 === $value;
	}

	/**
	 * Dispatch standard WooCommerce emails for a newly created order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool True when at least one dispatch path was attempted.
	 */
	private function dispatch_order_emails( $order, $email_template = null ) {
		if ( ! $order || ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'mailer' ) ) {
			Miguel::debug_log(
				'WooCommerce mailer unavailable for order email dispatch',
				array(
					'order_id' => $order ? $order->get_id() : 0,
				)
			);

			return false;
		}

		WC()->payment_gateways();
		WC()->shipping();

		$mailer = WC()->mailer();
		if ( ! $mailer ) {
			return false;
		}

		$dispatched = false;
		$templates = null !== $email_template ? array( $email_template ) : $this->get_default_email_templates_for_order( $order );

		foreach ( $templates as $template ) {
			$dispatched = $this->dispatch_single_order_email( $order, $mailer, $template ) || $dispatched;
		}

		Miguel::debug_log(
			'WooCommerce order emails dispatched',
			array(
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
				'email_template' => $email_template,
				'templates' => $templates,
				'dispatched' => $dispatched,
			)
		);

		return $dispatched;
	}

	/**
	 * Determine default email templates for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private function get_default_email_templates_for_order( $order ) {
		$templates = array( 'new_order' );

		switch ( $order->get_status() ) {
			case 'pending':
				$templates[] = 'customer_invoice';
				break;

			case 'on-hold':
				$templates[] = 'customer_on_hold_order';
				break;

			case 'processing':
				$templates[] = 'customer_processing_order';
				break;

			case 'completed':
				$templates[] = 'customer_completed_order';
				break;

			case 'failed':
				$templates[] = 'customer_failed_order';
				break;
		}

		return $templates;
	}

	/**
	 * Dispatch one WooCommerce email template.
	 *
	 * @param WC_Order  $order WooCommerce order.
	 * @param WC_Emails $mailer WooCommerce mailer.
	 * @param string    $template Email template identifier.
	 * @return bool
	 */
	private function dispatch_single_order_email( $order, $mailer, $template ) {
		switch ( $template ) {
			case 'new_order':
				if ( ! isset( $mailer->emails['WC_Email_New_Order'] ) ) {
					return false;
				}

				do_action( 'woocommerce_before_resend_order_emails', $order, 'new_order' );
				add_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
				$mailer->emails['WC_Email_New_Order']->trigger( $order->get_id(), $order, true );
				remove_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
				do_action( 'woocommerce_after_resend_order_email', $order, 'new_order' );
				return true;

			case 'customer_invoice':
				do_action( 'woocommerce_before_resend_order_emails', $order, 'customer_invoice' );
				$mailer->customer_invoice( $order );
				do_action( 'woocommerce_after_resend_order_email', $order, 'customer_invoice' );
				return true;

			case 'customer_on_hold_order':
				do_action( 'woocommerce_order_status_pending_to_on-hold_notification', $order->get_id(), $order ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				return true;

			case 'customer_processing_order':
				do_action( 'woocommerce_order_status_pending_to_processing_notification', $order->get_id(), $order );
				return true;

			case 'customer_completed_order':
				do_action( 'woocommerce_order_status_completed_notification', $order->get_id(), $order );
				return true;

			case 'customer_failed_order':
				do_action( 'woocommerce_order_status_failed_notification', $order->get_id(), $order );
				return true;
		}

		return false;
	}
}
