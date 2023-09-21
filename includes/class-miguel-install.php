<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Installation
 *
 * @package Miguel
 */
class Miguel_Install {

	/**
	 * Update plugin version.
	 */
	public static function update_version() {
		delete_option( 'miguel_version' );
		add_option( 'miguel_version', miguel()->version );
	}

	/**
	 * Create db tables.
	 */
	public static function install() {
		global $wpdb;

		$wpdb->hide_errors();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_table_schema() );
	}

	/**
	 * Get table schema.
	 *
	 * @return string
	 */
	private static function get_table_schema() {
		global $wpdb;

		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$schema = "
			CREATE TABLE {$wpdb->prefix}miguel_async_requests (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				guid BIGINT(20) UNSIGNED NOT NULL,
				status VARCHAR(100) NOT NULL,
				created DATETIME NOT NULL,
				order_id BIGINT(20) UNSIGNED NOT NULL,
				product_id BIGINT(20) UNSIGNED NOT NULL,
				download_id BIGINT(20) UNSIGNED NOT NULL,
				expected_duration INT,
				download_url TEXT,
				download_url_expires DATETIME,
				PRIMARY KEY (id)
			) $collate;
		";

		return $schema;
	}
}
