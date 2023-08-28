<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Timer
 *
 * @package Miguel
 */

?>
<p><?php esc_html_e( 'Your e-book is being prepared for download.', 'miguel' ); ?></p>
<p>
	<?php
	/* translators: %s: time */
		printf(
			esc_html__( 'The e-book will be generated in %s.', 'miguel' ),
			'<span id="timer">3 min 0 s</span>'
		);
		?>
</p>
<script>var miguel_duration = <?php echo esc_js( $time ); ?>;</script>
<?php wp_enqueue_script( 'miguel-script', plugins_url( '/assets/js/timer.js', MIGUEL_PLUGIN_FILE ) ); ?>
<?php
