<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Request
 *
 * @package Miguel
 */
class Miguel_Request {

	/**
	 * Order
	 *
	 * @var WC_Order
	 */
	protected $order;

	/**
	 * User
	 *
	 * @var WP_User|false
	 */
	protected $user;

	/**
	 * Item
	 *
	 * @var WC_Order_Item_Product
	 */
	protected $item;

	/**
	 * Format
	 *
	 * @var string
	 */
	protected $format;

	/**
	 * Constructor
	 *
	 * @param WC_Order              $order
	 * @param WC_Order_Item_Product $item
	 * @param string                $format
	 */
	public function __construct( $order, $item, $format ) {
		$this->order = $order;
		$this->item = $item;
		$this->format = $format;

		$user_id = $order->get_user_id();
		if ( $user_id > 0 ) {
			$this->user = get_user_by( 'id', $user_id );
		} else {
			$this->user = false;
		}
	}

	/**
	 * Get id
	 *
	 * @return string
	 */
	public function get_id() {
		return Miguel_Order_Utils::get_user_id_for_order( $this->order );
	}

	/**
	 * Get order id
	 *
	 * @return int
	 */
	public function get_order_id() {
		return $this->order->get_id();
	}

	/**
	 * Get email
	 *
	 * @return string
	 */
	public function get_email() {
		return Miguel_Order_Utils::get_email_for_order( $this->order );
	}

	/**
	 * Get full name
	 *
	 * @return string
	 */
	public function get_full_name() {
		return Miguel_Order_Utils::get_full_name_for_order( $this->order );
	}

	/**
	 * Get address
	 *
	 * @return string
	 */
	public function get_address() {
		return Miguel_Order_Utils::get_address_for_order( $this->order );
	}

	/**
	 * Get purchase date
	 *
	 * @return string|null
	 */
	public function get_purchase_date() {
		$paid_date = $this->order->get_date_paid();
		if ( ! $paid_date ) {
			return null;
		}

		$paid_date->setTimezone( new DateTimeZone( 'UTC' ) );
		return $paid_date->format( 'Y-m-d\TH:i:s.u\Z' );
	}

	/**
	 * Get language
	 *
	 * @return string
	 */
	public function get_language() {
		return Miguel_Order_Utils::get_language_for_order( $this->order );
	}

	/**
	 * Is valid
	 *
	 * @return bool
	 */
	public function is_valid() {
		return ! empty( $this->get_purchase_date() );
	}

	/**
	 * To array
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'target' => $this->format,
			'userInfo' => Miguel_Order_Utils::get_user_data_for_order_v2( $this->order ),
			'purchaseDate' => $this->get_purchase_date(),
			'orderInfo' => array(
				'code' => strval( $this->get_order_id() ),
				'soldPrice' => $this->order->get_item_total( $this->item, false, false ), // calculate price after discounts, before tax
				'currencyCode' => $this->order->get_currency(),
			),
		);
	}
}
