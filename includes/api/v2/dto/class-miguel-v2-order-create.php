<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderCreate value object.
 *
 * @package Miguel
 */
class Miguel_V2_Order_Create {

	/** @var string */
	private $code;

	/** @var Miguel_V2_Watermark_User */
	private $user;

	/** @var string|null */
	private $purchased_at;

	/** @var string */
	private $currency_code;

	/** @var Miguel_V2_Order_Create_Item[] */
	private $items;

	/** @var string */
	private $send_email;

	/** @var string|null */
	private $eshop_id;

	/** @var string|null */
	private $eshop_created_at;

	/** @var string|null */
	private $eshop_updated_at;

	/** @var string|null */
	private $source;

	/** @var string|null */
	private $social_drm_content;

	/** @var Miguel_V2_Order_Address|null */
	private $billing_address;

	/** @var Miguel_V2_Order_Address|null */
	private $shipping_address;

	/**
	 * Constructor.
	 *
	 * @param string                       $code               Order code.
	 * @param Miguel_V2_Watermark_User     $user               Order user.
	 * @param string|null                  $purchased_at       ISO-8601 or null.
	 * @param string                       $currency_code      Currency code.
	 * @param Miguel_V2_Order_Create_Item[] $items             Order items.
	 * @param string                       $send_email         "auto" or "disable".
	 * @param string|null                  $eshop_id           E-shop order id.
	 * @param string|null                  $eshop_created_at   ISO-8601 or null.
	 * @param string|null                  $eshop_updated_at   ISO-8601 or null.
	 * @param string|null                  $source             Optional source.
	 * @param string|null                  $social_drm_content Optional DRM content.
	 * @param Miguel_V2_Order_Address|null $billing_address    Optional billing.
	 * @param Miguel_V2_Order_Address|null $shipping_address   Optional shipping.
	 */
	public function __construct(
		$code,
		Miguel_V2_Watermark_User $user,
		$purchased_at,
		$currency_code,
		array $items,
		$send_email,
		$eshop_id,
		$eshop_created_at,
		$eshop_updated_at,
		$source = null,
		$social_drm_content = null,
		Miguel_V2_Order_Address $billing_address = null,
		Miguel_V2_Order_Address $shipping_address = null
	) {
		$this->code               = (string) $code;
		$this->user               = $user;
		$this->purchased_at       = ( null === $purchased_at ) ? null : (string) $purchased_at;
		$this->currency_code      = (string) $currency_code;
		$this->items              = $items;
		$this->send_email         = (string) $send_email;
		$this->eshop_id           = ( null === $eshop_id ) ? null : (string) $eshop_id;
		$this->eshop_created_at   = ( null === $eshop_created_at ) ? null : (string) $eshop_created_at;
		$this->eshop_updated_at   = ( null === $eshop_updated_at ) ? null : (string) $eshop_updated_at;
		$this->source             = ( null === $source ) ? null : (string) $source;
		$this->social_drm_content = ( null === $social_drm_content ) ? null : (string) $social_drm_content;
		$this->billing_address    = $billing_address;
		$this->shipping_address   = $shipping_address;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		$items = array();
		foreach ( $this->items as $item ) {
			$items[] = $item->to_array();
		}

		$arr = array(
			'code'            => $this->code,
			'user'            => $this->user->to_array(),
			'purchasedAt'     => $this->purchased_at,
			'currencyCode'    => $this->currency_code,
			'items'           => $items,
			'sendEmail'       => $this->send_email,
			'eshopId'         => $this->eshop_id,
			'eshopCreatedAt'  => $this->eshop_created_at,
			'eshopUpdatedAt'  => $this->eshop_updated_at,
			'source'          => $this->source,
			'socialDrmContent' => $this->social_drm_content,
		);

		if ( $this->billing_address instanceof Miguel_V2_Order_Address && ! $this->billing_address->is_empty() ) {
			$arr['billingAddress'] = $this->billing_address->to_array();
		}

		if ( $this->shipping_address instanceof Miguel_V2_Order_Address && ! $this->shipping_address->is_empty() ) {
			$arr['shippingAddress'] = $this->shipping_address->to_array();
		}

		return $arr;
	}
}
