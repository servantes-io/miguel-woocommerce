<?php
/**
 * Shop data for tests/smoke/checks.sh, run with `wp eval-file`: a book Miguel knows by its SKU, a
 * coupon, and a Miguel API key.
 *
 * @package Miguel\Tests
 */

$product = new WC_Product_Simple();
$product->set_name( 'Smoke book' );
$product->set_sku( 'SMOKE-BOOK' );
$product->set_regular_price( '100' );
$product->save();

$coupon = new WC_Coupon();
$coupon->set_code( 'smoke10' );
$coupon->set_discount_type( 'percent' );
$coupon->set_amount( 10 );
$coupon->save();

update_option( Miguel_API::SERVER_OPTION, Miguel_API::ENV_TEST );
update_option( Miguel_API::API_KEY_OPTION, 'smoke-token' );

// Otherwise the first wp-admin page redirects to WooCommerce's onboarding.
delete_transient( '_wc_activation_redirect' );
