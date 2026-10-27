<?php
/** Pickup display through the real admin shipping hook. */
class Test_Miguel_Pickup_Point_Display extends Miguel_Test_Case {
    public function test_shipping_hook_displays_and_escapes_each_carrier() {
        set_current_screen( 'shop_order' );
        foreach ( array( 'balikovna', 'packeta', 'gls' ) as $carrier ) {
            $order = wc_create_order();
            try {
                $order->update_meta_data( '_miguel_pickup_point', array(
                    'carrier' => $carrier, 'id' => '123',
                    'name' => '<script>name</script>', 'address' => 'Street & City',
                ) );
                $order->save();
                $shipping = new WC_Order_Item_Shipping();
                $order->add_item( $shipping );
                ob_start();
                do_action( 'woocommerce_after_order_itemmeta', $shipping->get_id(), $shipping, null );
                $html = ob_get_clean();
                $this->assertStringContainsString( $carrier . ' · ID: 123', $html );
                $this->assertStringContainsString( '&lt;script&gt;name&lt;/script&gt;', $html );
                $this->assertStringContainsString( 'Street &amp; City', $html );
                $this->assertStringNotContainsString( '<script>', $html );
            } finally {
                $order->delete( true );
            }
        }
    }

    public function test_missing_or_incomplete_metadata_and_product_items_are_hidden() {
        set_current_screen( 'shop_order' );
        $order = wc_create_order();
        try {
            foreach ( array( null, array(), array( 'name' => 'No ID' ) ) as $point ) {
                $order->update_meta_data( '_miguel_pickup_point', $point );
                $order->save();
                $shipping = new WC_Order_Item_Shipping();
                $order->add_item( $shipping );
                ob_start();
                do_action( 'woocommerce_after_order_itemmeta', $shipping->get_id(), $shipping, null );
                $this->assertSame( '', ob_get_clean() );
            }
            ob_start();
            do_action( 'woocommerce_after_order_itemmeta', 0, new WC_Order_Item_Product(), null );
            $this->assertSame( '', ob_get_clean() );
        } finally {
            $order->delete( true );
        }
    }
}
