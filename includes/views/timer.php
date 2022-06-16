<?php
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

/**
 * Timer
 *
 * @package WC_Wosa
 */

?>
<p><?php _e( 'Your e-book is being prepared for download.', 'wc-wosa' ); ?></p>
<p>
  <?php 
    printf(
      __( 'The e-book will be generated in %s.', 'wc-wosa' ),
      '<span id="timer">3 min 0 s</span>'
    );
  ?>
</p>
<script>var wc_wosa_duration = <?php echo esc_js( $time ); ?>;</script>
<script src="<?php echo plugins_url( '/assets/js/timer.js', WC_WOSA_PLUGIN_FILE ); ?>"></script><?php
