<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderCreateItem value object.
 *
 * @package Miguel
 */
class Miguel_V2_Order_Create_Item {

	/** @var string */
	private $code;

	/** @var float */
	private $sold_price;

	/** @var int */
	private $quantity;

	/** @var int|null */
	private $delivery_method_id;

	/**
	 * Constructor.
	 *
	 * @param string   $code               Product code.
	 * @param float    $sold_price         Per-unit price (excluding VAT).
	 * @param int      $quantity           Number of units.
	 * @param int|null $delivery_method_id Optional delivery method id.
	 */
	public function __construct( $code, $sold_price, $quantity, $delivery_method_id = null ) {
		$this->code               = (string) $code;
		$this->sold_price         = (float) $sold_price;
		$this->quantity           = (int) $quantity;
		$this->delivery_method_id = ( null === $delivery_method_id ) ? null : (int) $delivery_method_id;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		$arr = array(
			'code'      => $this->code,
			'soldPrice' => $this->sold_price,
			'quantity'  => $this->quantity,
		);

		if ( null !== $this->delivery_method_id ) {
			$arr['deliveryMethodId'] = $this->delivery_method_id;
		}

		return $arr;
	}
}
