<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 GetWatermarkedFileFromVariantRequest value object.
 *
 * @package Miguel
 */
class Miguel_V2_Watermarked_File_Request {

	/** @var string */
	private $target;

	/** @var Miguel_V2_Watermark_User */
	private $user_info;

	/** @var string */
	private $purchase_date;

	/** @var string */
	private $order_code;

	/** @var string */
	private $currency_code;

	/** @var float */
	private $sold_price;

	/**
	 * Constructor.
	 *
	 * @param string                   $target        Target FileFormat (epub, mobi, pdf, audio).
	 * @param Miguel_V2_Watermark_User $user_info     User info.
	 * @param string                   $purchase_date ISO-8601 purchase date.
	 * @param string                   $order_code    Order code for orderInfo.
	 * @param string                   $currency_code Currency code for orderInfo.
	 * @param float                    $sold_price    Per-unit price (excluding VAT).
	 */
	public function __construct( $target, Miguel_V2_Watermark_User $user_info, $purchase_date, $order_code, $currency_code, $sold_price ) {
		$this->target        = (string) $target;
		$this->user_info     = $user_info;
		$this->purchase_date = (string) $purchase_date;
		$this->order_code    = (string) $order_code;
		$this->currency_code = (string) $currency_code;
		$this->sold_price    = (float) $sold_price;
	}

	/**
	 * Target file format.
	 *
	 * @return string
	 */
	public function get_target() {
		return $this->target;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'target'       => $this->target,
			'userInfo'     => $this->user_info->to_array(),
			'purchaseDate' => $this->purchase_date,
			'orderInfo'    => array(
				'code'         => $this->order_code,
				'currencyCode' => $this->currency_code,
				'soldPrice'    => $this->sold_price,
			),
		);
	}
}
