<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Miguel order-finished callback: the shop decides the target order status.
 *
 * Miguel reports that an order settled and hands over the whole order; this route decides
 * whether and to what the WooCommerce status should change, from two operator settings.
 * Mirrors the PrestaShop module's setOrderStates.
 *
 * @package Miguel
 */
class Miguel_Order_Finished_Api {
	use Miguel_Rest_Auth_Trait;

	const STATUS_MIGUEL_ONLY_OPTION = 'miguel_order_status_miguel_only';
	const STATUS_MIXED_OPTION = 'miguel_order_status_mixed';

	/**
	 * The only Miguel state that triggers a status change.
	 */
	const FINISHED_STATE = 'finished';

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
			'/orders/(?P<id>\\d+)/finished',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'handle_order_finished' ),
				'permission_callback' => array( $this, 'validate_api_access' ),
			)
		);
	}

	/**
	 * Selectable target statuses for the settings page.
	 *
	 * @return array Slug => label, with the empty "do not change" option first.
	 */
	public static function get_status_choices() {
		$choices = array( '' => __( 'Do not change status', 'miguel' ) );

		foreach ( Miguel_Order_Status_Writer::get_order_statuses() as $slug => $label ) {
			$choices[ $slug ] = $label;
		}

		return $choices;
	}

	/**
	 * Handle a finished-order notification from Miguel.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_order_finished( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Read the route capture directly, not via get_param(): a JSON body's own top-level
		// "id" (Miguel's order id, in this payload's shape) otherwise outranks the URL match
		// in WP_REST_Request's parameter precedence and would resolve the wrong order.
		$url_params = $request->get_url_params();
		$order_id = isset( $url_params['id'] ) ? absint( $url_params['id'] ) : 0;
		if ( $order_id <= 0 ) {
			return new WP_Error(
				'order.invalid_id',
				esc_html__( 'Order ID must be a positive integer.', 'miguel' ),
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

		$miguel_state = isset( $payload['miguel_state'] ) ? (string) $payload['miguel_state'] : '';
		if ( self::FINISHED_STATE !== $miguel_state ) {
			return $this->unchanged( $order, 'waiting for status: ' . self::FINISHED_STATE );
		}

		$products = isset( $payload['products'] ) && is_array( $payload['products'] ) ? $payload['products'] : array();
		if ( count( $products ) < 1 ) {
			return $this->unchanged( $order, 'no products' );
		}

		$target_status = self::get_target_status( self::is_miguel_only( $products ) );
		if ( '' === $target_status ) {
			return $this->unchanged( $order, 'auto change not set' );
		}

		if ( ! Miguel_Order_Status_Writer::is_supported_order_status( $target_status ) ) {
			Miguel::log(
				sprintf(
					'Order-finished callback: configured target status "%s" is not a registered WooCommerce status; order %d left unchanged.',
					$target_status,
					$order_id
				),
				'warning'
			);

			return $this->unchanged( $order, 'configured status no longer exists' );
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

		return new WP_REST_Response(
			array(
				'id' => $result['order_id'],
				'status' => $result['status'],
				'changed' => true,
				'reason' => 'state changed',
				'idempotent_replay' => $result['idempotent_replay'],
			),
			200
		);
	}

	/**
	 * Whether the order holds Miguel products only.
	 *
	 * Evaluated per product, not per order: a single line item without formats flips the
	 * whole order to the mixed target. Same rule as the PrestaShop module.
	 *
	 * @param array $products Products from the callback payload.
	 * @return bool
	 */
	private static function is_miguel_only( $products ) {
		foreach ( $products as $product ) {
			$formats = isset( $product['formats'] ) && is_array( $product['formats'] ) ? $product['formats'] : array();
			if ( count( $formats ) < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The configured target status for this cart composition.
	 *
	 * @param bool $miguel_only Whether the cart holds Miguel products only.
	 * @return string Normalized status slug, or '' for "do not change".
	 */
	private static function get_target_status( $miguel_only ) {
		$option = $miguel_only ? self::STATUS_MIGUEL_ONLY_OPTION : self::STATUS_MIXED_OPTION;

		return Miguel_Order_Status_Writer::normalize_order_status( get_option( $option, '' ) );
	}

	/**
	 * A 200 response reporting that nothing was changed, and why.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason Machine-readable reason.
	 * @return WP_REST_Response
	 */
	private function unchanged( $order, $reason ) {
		return new WP_REST_Response(
			array(
				'id' => $order->get_id(),
				'status' => $order->get_status(),
				'changed' => false,
				'reason' => $reason,
				'idempotent_replay' => false,
			),
			200
		);
	}
}
