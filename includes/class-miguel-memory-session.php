<?php
/**
 * In-memory WooCommerce session.
 *
 * @package Miguel
 */

defined( 'ABSPATH' ) || exit;

/**
 * A WC_Session that keeps its data in memory only: no cookie, no database row.
 */
class Miguel_Memory_Session extends WC_Session {

	/**
	 * Nothing to load or hook.
	 */
	public function init() {}

	/**
	 * Never persists.
	 */
	public function save_data() {}

	/**
	 * Nothing to forget outside memory.
	 */
	public function forget_session() {}

	/**
	 * No cookie is ever set.
	 *
	 * @param bool $set Ignored.
	 */
	public function set_customer_session_cookie( $set ) {}

	/**
	 * The session always exists.
	 *
	 * @return bool
	 */
	public function has_session() {
		return true;
	}

	/**
	 * There is no cookie.
	 *
	 * @return false
	 */
	public function get_session_cookie() {
		return false;
	}

	/**
	 * Nothing to destroy.
	 */
	public function destroy_session() {}

	/**
	 * Fixed identifier of the throwaway session.
	 *
	 * @return string
	 */
	public function get_customer_id() {
		return 'miguel-preview';
	}
}
