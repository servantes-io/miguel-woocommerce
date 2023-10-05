<?php
/**
 * Timer
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<p><?php esc_html_e( 'Your e-book is being prepared for download.', 'miguel' ); ?></p>
<p>
	<?php
		printf(
			/* translators: %s: time */
			esc_html__( 'The e-book will be generated in %s.', 'miguel' ),
			'<span id="timer">3 min 0 s</span>'
		);
		?>
</p>
<script>var miguel_duration = <?php echo esc_js( $args['time'] ); ?>;</script>
<?php wp_enqueue_script( 'miguel-script', plugins_url( '/assets/js/timer.js', MIGUEL_PLUGIN_FILE ), array(), '1.0' ); ?>
<?php
