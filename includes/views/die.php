<?php
/**
 * Die page
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$miguel_title = isset( $args['title'] ) ? $args['title'] : esc_html__( 'MIGUEL', 'miguel' );
$content      = isset( $args['content'] ) ? $args['content'] : '';

?>
<!doctype html>
<html>
	<head>
		<meta charset="utf-8">
		<meta name="robots" content="noindex,follow">
		<title><?php echo esc_html( $miguel_title ); ?></title>
		<?php wp_enqueue_style( 'miguel-stylesheet', plugins_url( '/assets/css/style.css', MIGUEL_PLUGIN_FILE ) ); ?>
	</head>
	<body><?php echo esc_html( $content ); ?></body>
</html>
<?php
