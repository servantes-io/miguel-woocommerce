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
		$purchase_date = Miguel_Order_Utils::get_purchase_date_for_order( $order );
		if ( ! $purchase_date ) {
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
			$purchase_date,
			strval( $order->get_id() ),
			$order->get_currency(),
			$order->get_item_total( $item, false, false )
		);
	}

	/**
	 * Build the request DTO from a download permission whose order no longer exists.
	 *
	 * WooCommerce keeps serving such a permission, so the customer keeps the link. The permission knows the
	 * customer's e-mail, account and when access was granted, but not their name, address or the price paid:
	 * the price is sent as 0 in the shop's currency and the deleted order's ID stays the order code.
	 *
	 * @param WC_Customer_Download $permission Download permission.
	 * @param Miguel_File          $file       File entity (provides target format).
	 * @return Miguel_V2_Watermarked_File_Request|null Null when the permission has no grant date.
	 */
	public function map_permission( $permission, $file ) {
		$access_granted = $permission->get_access_granted();
		if ( ! $access_granted ) {
			return null;
		}

		$user_id = $permission->get_user_id();
		$wp_user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

		$user = new Miguel_V2_Watermark_User(
			$permission->get_user_email(),
			get_user_locale( $wp_user ),
			$wp_user ? strval( $wp_user->ID ) : null
		);

		return new Miguel_V2_Watermarked_File_Request(
			$file->get_format(),
			$user,
			$access_granted->format( DateTime::ATOM ),
			strval( $permission->get_order_id() ),
			get_woocommerce_currency(),
			0
		);
	}
}
