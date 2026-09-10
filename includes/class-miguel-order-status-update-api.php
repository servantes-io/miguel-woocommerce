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
	 * Status writer.
	 *
	 * @var Miguel_Order_Status_Writer
	 */
	private $writer;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface   $hook_manager Hook manager.
	 * @param Miguel_Order_Status_Writer|null $writer       Status writer.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_Order_Status_Writer $writer = null ) {
		$this->hook_manager = $hook_manager;
		$this->writer = $writer ? $writer : new Miguel_Order_Status_Writer();
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

		$target_status = Miguel_Order_Status_Writer::normalize_order_status( $payload['status'] );
		if ( '' === $target_status || ! Miguel_Order_Status_Writer::is_supported_request_status( $target_status ) ) {
			return new WP_Error(
				'order.status_invalid',
				esc_html__( 'Order status is not supported by WooCommerce.', 'miguel' ),
				array(
					'status' => 409,
					'order_status' => $payload['status'],
					'allowed_statuses' => Miguel_Order_Status_Writer::get_supported_request_statuses(),
				)
			);
		}

		$idempotency_key = Miguel_Order_Status_Writer::read_idempotency_key( $request );
		if ( '' === $idempotency_key ) {
			return new WP_Error(
				'idempotency.key_required',
				esc_html__( 'Idempotency-Key header is required.', 'miguel' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->writer->apply( $order_id, $target_status, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = array(
			'id' => $result['order_id'],
			'status' => $result['status'],
			'idempotent_replay' => $result['idempotent_replay'],
		);

		// The paid route reports its own outcome: a shop that declines to complete the payment
		// answers 200 with paid:false and a reason, the same shape the finished route uses for
		// changed:false. An operator's gateway configuration is not a server fault, and a 500
		// here is what made Miguel retry the same order once a minute indefinitely.
		if ( 'paid' === $target_status ) {
			$body['paid'] = ! empty( $result['paid'] );
			$body['reason'] = isset( $result['reason'] ) ? $result['reason'] : null;
		}

		return new WP_REST_Response( $body, 200 );
	}
}
