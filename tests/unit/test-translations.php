<?php
/**
 * Class Miguel_Test_Translations
 *
 * @package Miguel\Tests
 */

/**
 * Where WordPress looks for the plugin's translations.
 */
class Miguel_Test_Translations extends WP_UnitTestCase {

	/**
	 * Since WordPress 6.7 the translations folder of an active plugin is registered from its header,
	 * before the plugin loads, and a translation looked up before `init` resolves against it and is
	 * cached for the rest of the request. A header without `Domain Path` registers the plugin root,
	 * which holds no catalogue, so an early lookup (the Miguel gateway translates its labels when
	 * WooCommerce builds its gateways, which some plugins do before `init`) left every Miguel string
	 * untranslated.
	 */
	public function test_header_points_wordpress_at_the_translation_catalogues(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( MIGUEL_PLUGIN_FILE, false, false );

		$this->assertSame( 'miguel', $data['TextDomain'] );
		$this->assertSame( '/languages', $data['DomainPath'] );
		$this->assertFileExists( dirname( MIGUEL_PLUGIN_FILE ) . $data['DomainPath'] . '/miguel-cs_CZ.po' );
	}
}
