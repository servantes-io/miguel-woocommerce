<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Settings with dependency injection for better testability
 *
 * @package Miguel
 */
class Miguel_Settings extends WC_Settings_Page {

	/**
	 * Section of the Miguel tab holding the product pairing table.
	 */
	const PRODUCT_PAIRING_SECTION = 'product-pairing';

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private Miguel_Hook_Manager_Interface $hook_manager;

	/**
	 * Init settings page with dependency injection
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager ) {
		$this->hook_manager = $hook_manager;
		$this->id = 'miguel';
		$this->label = __( 'Miguel', 'miguel' );

		parent::__construct();
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		$this->hook_manager->add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		$this->hook_manager->add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		$this->hook_manager->add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
	}

	/**
	 * Get hook manager (for testing purposes)
	 *
	 * @return Miguel_Hook_Manager_Interface|null
	 */
	public function get_hook_manager() {
		return $this->hook_manager;
	}

	/**
	 * Get settings config for WooCommerce Settings page.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = array_filter(
			array(
				array(
					'id' => 'miguel_api_options',
					'type' => 'title',
					'title' => __( 'Miguel API', 'miguel' ),
					'desc' => __( 'miguel_settings_description', 'miguel' ),
				),
				array(
					'id' => Miguel_API::API_KEY_OPTION,
					'css' => 'min-width: 350px;',
					'type' => 'text',
					'title' => __( 'API key', 'miguel' ),
					'desc' => __( 'To setup safe communication between your e-shop and our server.', 'miguel' ),
				),
				array(
					'id'      => Miguel_API::SERVER_OPTION,
					'type'    => 'select',
					'title'   => __( 'API server', 'miguel' ),
					'options' => array(
						Miguel_API::ENV_PROD    => __( 'Production', 'miguel' ),
						Miguel_API::ENV_STAGING => __( 'Staging', 'miguel' ),
						Miguel_API::ENV_TEST    => __( 'Test', 'miguel' ),
					),
					'default' => Miguel_API::ENV_PROD,
				),
				array(
					'id' => 'miguel_api_options',
					'type' => 'sectionend',
				),

				array(
					'id'    => 'miguel_order_options',
					'type'  => 'title',
					'title' => __( 'Orders', 'miguel' ),
				),
				array(
					'id'      => Miguel_Orders::SEND_EMAIL_OPTION,
					'type'    => 'checkbox',
					'title'   => __( 'Send order emails from Miguel', 'miguel' ),
					'desc'    => __( 'When enabled, Miguel sends an email to the customer with links to download the books. When disabled, Miguel does not send any email.', 'miguel' ),
					'default' => 'no',
				),
				array(
					'id'       => Miguel_Orders::DELETED_STATUSES_OPTION,
					'type'     => 'multiselect',
					'class'    => 'wc-enhanced-select',
					'css'      => 'min-width: 350px;',
					'title'    => __( 'Statuses that remove the order from Miguel', 'miguel' ),
					'desc'     => __( 'Reaching one of these statuses removes the order from Miguel: the customer loses access to the books and their download links stop working. Trashing an order always removes it, whatever is selected here.', 'miguel' ),
					'options'  => Miguel_Order_Status_Writer::get_order_statuses(),
					'default'  => Miguel_Orders::DEFAULT_DELETED_STATUSES,
					'desc_tip' => false,
				),
				array(
					'id'   => 'miguel_order_options',
					'type' => 'sectionend',
				),

				// The two targets share one explanation rather than repeating it per field —
				// the section heading is what gives each label its context.
				array(
					'id'    => 'miguel_order_status_options',
					'type'  => 'title',
					'title' => __( 'Automatic order status change', 'miguel' ),
					'desc'  => __( 'When Miguel finishes processing an order, move it to the status chosen here. Leave both on "Do not change status" to disable.', 'miguel' ),
				),
				array(
					'id'      => Miguel_Order_Finished_Api::STATUS_MIGUEL_ONLY_OPTION,
					'type'    => 'select',
					'title'   => __( 'Order with only Miguel books', 'miguel' ),
					'options' => Miguel_Order_Finished_Api::get_status_choices(),
					'default' => '',
				),
				array(
					'id'      => Miguel_Order_Finished_Api::STATUS_MIXED_OPTION,
					'type'    => 'select',
					'title'   => __( 'Order with Miguel books and other products', 'miguel' ),
					'options' => Miguel_Order_Finished_Api::get_status_choices(),
					'default' => '',
				),
				array(
					'id'   => 'miguel_order_status_options',
					'type' => 'sectionend',
				),

				array(
					'id'    => 'miguel_product_options',
					'type'  => 'title',
					'title' => __( 'Products', 'miguel' ),
				),
				array(
					'id'      => Miguel_Product_Code_Source::SUFFIX_OPTION,
					'css'     => 'min-width: 350px;',
					'type'    => 'text',
					'title'   => __( 'Printed-book code suffix', 'miguel' ),
					'desc'    => __( 'Appended to a printed book\'s SKU to form its Miguel product code — e.g. ":print" makes SKU "harry-potter" resolve as "harry-potter:print". Leave empty to disable printed-book pairing. Must match the suffix configured on the Miguel platform.', 'miguel' ),
					'default' => '',
				),
				array(
					'id'   => 'miguel_product_options',
					'type' => 'sectionend',
				),
			)
		);

		return $settings;
	}

	/**
	 * Sections of the Miguel tab: the settings, and the product pairing table.
	 *
	 * @return array
	 */
	protected function get_own_sections() {
		return array(
			''                            => __( 'Settings', 'miguel' ),
			self::PRODUCT_PAIRING_SECTION => __( 'Product pairing', 'miguel' ),
		);
	}

	/**
	 * Display settings.
	 */
	public function output() {
		global $current_section;

		if ( self::PRODUCT_PAIRING_SECTION === $current_section ) {
			$this->output_product_pairing();
			return;
		}

		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings.
	 */
	public function save() {
		global $current_section;

		// The pairing section only reads: it has no fields to save and must not reconnect.
		if ( self::PRODUCT_PAIRING_SECTION === $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		WC_Admin_Settings::save_fields( $settings );
		$this->connect_to_miguel_api();
	}

	/**
	 * Display the product pairing table: every code a shop product exposes, and every
	 * Miguel product, each marked as paired or present on one side only.
	 */
	private function output_product_pairing() {
		// Nothing on this section is saved; a save button would only invite a reconnect.
		$GLOBALS['hide_save_button'] = true;

		echo '<h2>' . esc_html__( 'Product pairing', 'miguel' ) . '</h2>';
		echo '<p>' . esc_html__( 'Compares the Miguel codes of your products (by the same rules as orders from Miguel are paired) with the products in Miguel. Letter case does not matter. Nothing is changed in your e-shop or in Miguel.', 'miguel' ) . '</p>';

		$rows = ( new Miguel_Product_Pairing() )->get_rows();
		if ( is_wp_error( $rows ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $rows->get_error_message() ) . '</p></div>';
			return;
		}

		$labels = array(
			Miguel_Product_Pairing::STATUS_PAIRED      => __( 'Paired', 'miguel' ),
			Miguel_Product_Pairing::STATUS_ESHOP_ONLY  => __( 'Only in e-shop', 'miguel' ),
			Miguel_Product_Pairing::STATUS_MIGUEL_ONLY => __( 'Only in Miguel', 'miguel' ),
		);

		$counts = array_count_values( array_column( $rows, 'status' ) );
		$totals = array();
		foreach ( $labels as $status => $label ) {
			$totals[] = $label . ': ' . ( $counts[ $status ] ?? 0 );
		}
		echo '<p>' . esc_html( implode( ' · ', $totals ) ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Code', 'miguel' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'miguel' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Product in e-shop', 'miguel' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Product in Miguel', 'miguel' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No products found in the e-shop or in Miguel.', 'miguel' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$status = esc_html( $labels[ $row['status'] ] );
			if ( $row['is_duplicate'] ) {
				$status .= '<br /><strong>' . esc_html__( 'Duplicate code: matches several products in the e-shop', 'miguel' ) . '</strong>';
			}

			$shop_products = array();
			foreach ( $row['product_ids'] as $product_id ) {
				$shop_products[] = $this->get_product_link( $product_id );
			}

			$miguel_product = esc_html( (string) $row['miguel_name'] );
			if ( null !== $row['miguel_code'] && $row['miguel_code'] !== $row['code'] ) {
				// Paired regardless of case, but spelled differently in Miguel.
				$miguel_product .= '<br /><code>' . esc_html( $row['miguel_code'] ) . '</code>';
			}

			echo '<tr>';
			echo '<td><code>' . esc_html( $row['code'] ) . '</code></td>';
			echo '<td>' . $status . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '<td>' . implode( '<br />', $shop_products ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in get_product_link().
			echo '<td>' . $miguel_product . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Escaped name of a shop product, linked to its edit screen (a variation's parent) when the user may edit it.
	 *
	 * @param int $product_id Product ID.
	 * @return string HTML.
	 */
	private function get_product_link( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return esc_html( '#' . $product_id );
		}

		$edit_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$link    = get_edit_post_link( $edit_id, 'raw' );
		$name    = esc_html( $product->get_name() );

		return $link ? '<a href="' . esc_url( $link ) . '">' . $name . '</a>' : $name;
	}

	/**
	 * Attempt to connect using current API settings.
	 *
	 */
	private function connect_to_miguel_api() {
		$api_key = trim( (string) get_option( Miguel_API::API_KEY_OPTION, '' ) );
		if ( '' === $api_key ) {
			WC_Admin_Settings::add_error( __( 'Miguel connection skipped: API key is required.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$api_url = Miguel_API::getServerUrl( Miguel_API::getServer() );
		if ( false === $api_url || '' === trim( (string) $api_url ) ) {
			WC_Admin_Settings::add_error( __( 'Miguel API URL is not configured.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		$base_url = $this->get_canonical_shop_url();
		$client   = new Miguel_V2_Client( $api_url, $api_key );
		$result   = $client->connect(
			new Miguel_V2_Connect_Request(
				$this->get_woocommerce_version(),
				$this->get_module_version(),
				$base_url,
				$this->build_base_uri( $base_url )
			)
		);

		if ( true === $result ) {
			update_option( 'miguel_api_connected', 'yes' );
			update_option( 'miguel_api_last_connected_at', gmdate( 'c' ) );
			WC_Admin_Settings::add_message( __( 'Miguel API connection successful.', 'miguel' ) );
			return;
		}

		$code = $result->get_error_code();
		if ( 'miguel.http_401' === $code || 'miguel.http_403' === $code ) {
			Miguel::log( 'Miguel connect unauthorized: ' . $result->get_error_message(), 'error' );
			WC_Admin_Settings::add_error( __( 'Miguel API key is invalid or unauthorized.', 'miguel' ) );
			update_option( 'miguel_api_connected', 'no' );
			return;
		}

		Miguel::log( 'Miguel connect request failed: ' . $code . ' ' . $result->get_error_message(), 'error' );
		WC_Admin_Settings::add_error( __( 'Connection to Miguel API failed. Please verify API key and try again.', 'miguel' ) );
		update_option( 'miguel_api_connected', 'no' );
	}

	/**
	 * Build a canonical base URI path from an absolute shop URL.
	 *
	 * @param string $base_url Absolute shop base URL.
	 * @return string
	 */
	private function build_base_uri( $base_url ) {
		$path = wp_parse_url( (string) $base_url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Get WooCommerce runtime version.
	 *
	 * @return string
	 */
	private function get_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		if ( function_exists( 'WC' ) && WC() && isset( WC()->version ) ) {
			return (string) WC()->version;
		}

		return '';
	}

	/**
	 * Get Miguel plugin module version.
	 *
	 * @return string
	 */
	private function get_module_version() {
		return (string) miguel()->version;
	}

	/**
	 * Build canonical base URL of current shop.
	 *
	 * @return string
	 */
	private function get_canonical_shop_url() {
		$home_url = home_url( '/' );
		$parts = wp_parse_url( $home_url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( $home_url );
		}

		$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : ( is_ssl() ? 'https' : 'http' );
		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = trim( (string) ( $parts['path'] ?? '' ), '/' );

		$canonical_url = $scheme . '://' . $host . $port;
		if ( '' !== $path ) {
			$canonical_url .= '/' . $path;
		}

		return untrailingslashit( $canonical_url );
	}
}
