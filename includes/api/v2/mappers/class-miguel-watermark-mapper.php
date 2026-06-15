<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Maps a WooCommerce order/item/file to a v2 watermarked-file request.
 *
 * @package Miguel
 */
class Miguel_Watermark_Mapper {

	/**
	 * Build the request DTO.
	 *
	 * @param WC_Order              $order Order object.
	 * @param WC_Order_Item_Product $item  Order item.
	 * @param Miguel_File           $file  File entity (provides target format).
	 * @return Miguel_V2_Watermarked_File_Request|null Null when the order is not paid.
	 */
	public function map( $order, $item, $file ) {
		$paid_date = $order->get_date_paid();
		if ( ! $paid_date ) {
			return null;
		}

		$user_data = Miguel_Order_Utils::get_user_data_for_order( $order );

		$user = new Miguel_V2_Watermark_User(
			$user_data['email'],
			$user_data['lang'],
			$user_data['id'],
			$user_data['full_name'],
			$user_data['address']
		);

		return new Miguel_V2_Watermarked_File_Request(
			$file->get_format(),
			$user,
			$paid_date->format( DateTime::ATOM ),
			strval( $order->get_id() ),
			$order->get_currency(),
			$order->get_item_total( $item, false, false )
		);
	}
}
