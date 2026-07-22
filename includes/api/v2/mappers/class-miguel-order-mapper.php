<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Maps a WooCommerce order to a v2 OrderCreate DTO.
 *
 * @package Miguel
 */
class Miguel_Order_Mapper {

	/**
	 * Product code source.
	 *
	 * @var Miguel_Product_Code_Source
	 */
	private $code_source;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->code_source = new Miguel_Product_Code_Source();
	}

	/**
	 * Build the OrderCreate DTO.
	 *
	 * @param WC_Order $order      Order object.
	 * @param bool     $send_email Whether Miguel's backend should send order emails.
	 * @return Miguel_V2_Order_Create|null Null when there are no Miguel items.
	 */
	public function map( $order, $send_email = false ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$item_total = $order->get_item_total( $item, false, false );
			$quantity   = (int) $item->get_quantity();

			foreach ( $this->get_miguel_products_from_item( $product, $item_total ) as $miguel_product ) {
				$items[] = new Miguel_V2_Order_Create_Item(
					$miguel_product['code'],
					$miguel_product['sold_price'],
					$quantity
				);
			}
		}

		if ( empty( $items ) ) {
			return null;
		}

		$user_data = Miguel_Order_Utils::get_user_data_for_order( $order, true );
		$user      = new Miguel_V2_Watermark_User(
			$user_data['email'],
			$user_data['lang'],
			$user_data['id'],
			$user_data['full_name'],
			$user_data['address']
		);

		return new Miguel_V2_Order_Create(
			strval( $order->get_id() ),
			$user,
			Miguel_Order_Utils::get_purchase_date_for_order( $order ),
			$order->get_currency(),
			$items,
			$send_email ? 'auto' : 'disable',
			strval( $order->get_id() ),
			$this->format_date( $order->get_date_created() ),
			$this->format_date( $order->get_date_modified() ),
			'woocommerce',
			null,
			$this->build_billing_address( $order ),
			$this->build_shipping_address( $order )
		);
	}

	/**
	 * Format a WC date as ISO-8601, or null.
	 *
	 * @param WC_DateTime|null $date Date object.
	 * @return string|null
	 */
	private function format_date( $date ) {
		return $date ? $date->format( DateTime::ATOM ) : null;
	}

	/**
	 * Build billing address DTO from the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Address
	 */
	private function build_billing_address( $order ) {
		return new Miguel_V2_Order_Address(
			array(
				'fullName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'company'  => $order->get_billing_company(),
				'address1' => $order->get_billing_address_1(),
				'address2' => $order->get_billing_address_2(),
				'city'     => $order->get_billing_city(),
				'state'    => $order->get_billing_state(),
				'zip'      => $order->get_billing_postcode(),
				'country'  => $order->get_billing_country(),
				'phone'    => $order->get_billing_phone(),
			)
		);
	}

	/**
	 * Build shipping address DTO from the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return Miguel_V2_Order_Address
	 */
	private function build_shipping_address( $order ) {
		// get_shipping_phone() was added in WooCommerce 5.6; guard for older versions.
		$phone = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : null;

		return new Miguel_V2_Order_Address(
			array(
				'fullName' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'company'  => $order->get_shipping_company(),
				'address1' => $order->get_shipping_address_1(),
				'address2' => $order->get_shipping_address_2(),
				'city'     => $order->get_shipping_city(),
				'state'    => $order->get_shipping_state(),
				'zip'      => $order->get_shipping_postcode(),
				'country'  => $order->get_shipping_country(),
				'phone'    => $phone,
			)
		);
	}

	/**
	 * Get Miguel products (code + per-unit price) from an order item's product.
	 *
	 * @param WC_Product $product    Product object.
	 * @param float      $item_total Per-unit price of the item (excluding VAT).
	 * @return array Array of array{code:string, sold_price:float}.
	 */
	private function get_miguel_products_from_item( $product, $item_total ) {
		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			return $this->get_miguel_products_from_bundle( $bundle_ids, $item_total );
		}

		$products = array();
		foreach ( $this->code_source->get_codes( $product ) as $code ) {
			$products[] = array(
				'code'       => $code,
				'sold_price' => $item_total,
			);
		}

		return $products;
	}

	/**
	 * Get Miguel products from a bundle with proportional per-unit prices.
	 *
	 * @param array $bundle_ids   Bundled product IDs (array keys).
	 * @param float $bundle_total Per-unit price of the bundle (excluding VAT).
	 * @return array Array of array{code:string, sold_price:float}.
	 */
	private function get_miguel_products_from_bundle( $bundle_ids, $bundle_total ) {
		$products            = array();
		$bundled_items       = array();
		$total_regular_price = 0;

		foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
			$bundled_product = wc_get_product( $bundle_product_id );
			if ( ! $bundled_product ) {
				continue;
			}

			$nested_codes = $this->extract_all_miguel_codes( $bundled_product );
			if ( empty( $nested_codes ) ) {
				continue;
			}

			$regular_price = floatval( $bundled_product->get_regular_price() );
			if ( $regular_price <= 0 ) {
				$regular_price = floatval( $bundled_product->get_price() );
			}

			$bundled_items[]      = array(
				'codes'         => $nested_codes,
				'regular_price' => $regular_price,
			);
			$total_regular_price += $regular_price;
		}

		foreach ( $bundled_items as $bundled_item ) {
			if ( $total_regular_price > 0 ) {
				$price_ratio      = $bundled_item['regular_price'] / $total_regular_price;
				$calculated_price = $bundle_total * $price_ratio;
			} else {
				$calculated_price = $bundle_total / count( $bundled_items );
			}

			foreach ( $bundled_item['codes'] as $code ) {
				$products[] = array(
					'code'       => $code,
					'sold_price' => round( $calculated_price, 2 ),
				);
			}
		}

		return $products;
	}

	/**
	 * Extract all Miguel codes from a product, including nested bundles.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Unique Miguel codes.
	 */
	private function extract_all_miguel_codes( $product ) {
		$miguel_codes = array();

		$bundle_ids = $product->get_meta( '_bundle_ids', true );
		if ( ! empty( $bundle_ids ) ) {
			foreach ( array_keys( $bundle_ids ) as $bundle_product_id ) {
				$bundled_product = wc_get_product( $bundle_product_id );
				if ( $bundled_product ) {
					foreach ( $this->extract_all_miguel_codes( $bundled_product ) as $code ) {
						if ( ! in_array( $code, $miguel_codes, true ) ) {
							$miguel_codes[] = $code;
						}
					}
				}
			}
		}

		foreach ( $this->code_source->get_codes( $product ) as $code ) {
			if ( ! in_array( $code, $miguel_codes, true ) ) {
				$miguel_codes[] = $code;
			}
		}

		return $miguel_codes;
	}
}
