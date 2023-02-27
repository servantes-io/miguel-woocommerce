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
<p><?php _e( 'Your e-book is being prepared for download.', 'miguel' ); ?></p>
<p>
  <?php
    printf(
      __( 'The e-book will be generated in %s.', 'miguel' ),
      '<span id="timer">3 min 0 s</span>'
    );
  ?>
</p>
<script>var miguel_duration = <?php echo esc_js( $time ); ?>;</script>
<script src="<?php echo plugins_url( '/assets/js/timer.js', MIGUEL_PLUGIN_FILE ); ?>"></script><?php
