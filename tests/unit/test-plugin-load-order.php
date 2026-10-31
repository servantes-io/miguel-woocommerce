<?php
/**
 * Tests that the plugin loads before WooCommerce.
 *
 * @package Miguel\Tests
 */
class Test_Miguel_Plugin_Load_Order extends Miguel_Test_Case {

	/**
	 * WordPress loads 'miguel/' before 'woocommerce/', so the files Miguel includes on load must not
	 * need a WooCommerce class. The suite's bootstrap loads WooCommerce first, so this runs the
	 * includes in a separate PHP process without it.
	 */
	public function test_includes_load_without_woocommerce() {
		$script = sprintf(
			'define( "ABSPATH", "/" ); define( "MIGUEL_PLUGIN_FILE", %1$s ); function is_admin() { return true; }'
			. ' include %2$s; ( new ReflectionClass( "Miguel" ) )->newInstanceWithoutConstructor()->includes(); echo "loaded";',
			var_export( MIGUEL_PROJECT_DIR . '/miguel.php', true ),
			var_export( MIGUEL_PROJECT_DIR . '/includes/class-miguel.php', true )
		);

		exec( escapeshellarg( PHP_BINARY ) . ' -n -r ' . escapeshellarg( $script ) . ' 2>&1', $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
		$this->assertSame( 'loaded', implode( "\n", $output ) );
	}
}
