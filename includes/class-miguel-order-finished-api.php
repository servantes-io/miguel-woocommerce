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
	private Miguel_Hook_Manager_Interface $hook_manager;

	/**
	 * Status writer.
	 *
	 * @var Miguel_Order_Status_Writer
	 */
	private Miguel_Order_Status_Writer $writer;

	/**
	 * Order mapper, used only for its "does this line item export a Miguel code" rule.
	 *
	 * @var Miguel_Order_Mapper
	 */
	private Miguel_Order_Mapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface   $hook_manager Hook manager.
	 * @param Miguel_Order_Status_Writer|null $writer       Status writer.
	 * @param Miguel_Order_Mapper|null        $mapper       Order mapper.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_Order_Status_Writer $writer = null, Miguel_Order_Mapper $mapper = null ) {
		$this->hook_manager = $hook_manager;
		$this->writer = $writer ? $writer : new Miguel_Order_Status_Writer();
		$this->mapper = $mapper ? $mapper : new Miguel_Order_Mapper();
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
		$order_id = absint( $url_params['id'] ?? 0 );
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

		$miguel_state = (string) ( $payload['miguel_state'] ?? '' );
		if ( self::FINISHED_STATE !== $miguel_state ) {
			return $this->unchanged( $order, 'waiting for status: ' . self::FINISHED_STATE );
		}

		$products = isset( $payload['products'] ) && is_array( $payload['products'] ) ? $payload['products'] : array();
		if ( count( $products ) < 1 ) {
			return $this->unchanged( $order, 'no products' );
		}

		$target_status = self::get_target_status( $this->is_miguel_only( $order ) );
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
	 * Decided from the WooCommerce order's own line items, not from the callback payload:
	 * an order is Miguel-only when it has at least one product line item and *every* product
	 * line item exports at least one Miguel code (Miguel_Order_Mapper::has_miguel_codes(),
	 * the same rule that decides what is sent to Miguel in the first place). Evaluated per
	 * line item — one ordinary product flips the whole order to the mixed target.
	 *
	 * **Why this differs from PrestaShop.** The PrestaShop module reads the same decision out
	 * of the payload: it pushes *every* order line to Miguel (createArrayFromSimpleProduct
	 * skips only an empty product_reference), so a non-Miguel line reaches Miguel, matches no
	 * Product, and comes back with `formats: []` — that absence is its "mixed" signal. This
	 * plugin does not do that: Miguel_Order_Mapper drops line items that resolve to no Miguel
	 * code, so they are never sent and the callback's `products[]` is all-Miguel by
	 * construction. Reading it here would report every mixed order as Miguel-only, and would
	 * fire backwards on an all-Miguel order holding one code that does not resolve in the
	 * Miguel workspace. The shop's own line items are the ground truth, and we have them.
	 *
	 * Line items that are not WC_Order_Item_Product (shipping, fees, taxes) are ignored — they
	 * are not products the customer bought, and the mapper ignores them too. A line item whose
	 * product was deleted (get_product() returns null) counts as non-Miguel: nothing proves it
	 * was a book, and the mixed target is the conservative answer for an order that may still
	 * need manual handling.
	 *
	 * An order with no product line items at all is *not* Miguel-only, for the same reason.
	 * That case is practically unreachable: the handler has already required a non-empty
	 * `products[]` in the payload, which only an order with Miguel line items produces.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_miguel_only( $order ) {
		$has_product_item = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$has_product_item = true;

			$product = $item->get_product();
			if ( ! $product || ! $this->mapper->has_miguel_codes( $product ) ) {
				return false;
			}
		}

		return $has_product_item;
	}

	/**
	 * The configured target status for this order composition.
	 *
	 * @param bool $miguel_only Whether the order holds Miguel products only.
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
