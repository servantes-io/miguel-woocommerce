<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Die page
 *
 * @package WC_Wosa
 */

$title = isset( $title ) ? $title : __( 'WOSA', 'wc-wosa' );
$content = isset( $content ) ? $content : '';

?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,follow">
    <title><?php echo esc_html( $title ); ?></title>
    <link rel="stylesheet" href="<?php echo plugins_url( '/assets/css/style.css', WC_WOSA_PLUGIN_FILE ); ?>">
  </head>
  <body><?php echo $content; ?></body>
</html><?php
