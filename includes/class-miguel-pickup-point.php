<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The pickup point Miguel sends with an order, and the meta the shop's carrier plugin keeps it in.
 *
 * Miguel knows no carrier plugin: it sends one neutral order meta entry, `_miguel_pickup_point`,
 * whose value is `{carrier, id, name, address}` (`carrier` is `packeta`, `balikovna` or `gls`,
 * `address` one optional string). Shipmall exports an order's branch from the carrier plugins'
 * own meta, so this class names those keys for the carrier plugin the shop has active. The keys
 * are the ones the plugins write at their own checkout.
 *
 * @package Miguel
 */
class Miguel_Pickup_Point {

	/**
	 * Key of the neutral order meta entry.
	 */
	const META_KEY = '_miguel_pickup_point';

	/**
	 * Carrier => the directory of the carrier plugin that keeps its pickup points.
	 */
	const PLUGINS = array(
		'packeta'   => 'wc-zasilkovna',
		'balikovna' => 'toret-ceska-posta',
		'gls'       => 'wpify-woo-gls',
	);

	/**
	 * The neutral entry's value.
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Constructor.
	 *
	 * @param mixed $value The neutral entry's value.
	 */
	private function __construct( $value ) {
		$this->value = $value;
	}

	/**
	 * The pickup point of an order create payload.
	 *
	 * @param array $payload Order create payload.
	 * @return Miguel_Pickup_Point|null Null when its meta_data has no neutral entry.
	 */
	public static function from_payload( $payload ) {
		if ( empty( $payload['meta_data'] ) || ! is_array( $payload['meta_data'] ) ) {
			return null;
		}

		foreach ( $payload['meta_data'] as $meta ) {
			if ( is_array( $meta ) && self::META_KEY === ( $meta['key'] ?? null ) ) {
				return new self( $meta['value'] ?? null );
			}
		}

		return null;
	}

	/**
	 * The carrier as sent.
	 *
	 * @return string
	 */
	public function carrier() {
		return is_array( $this->value ) && is_scalar( $this->value['carrier'] ?? null ) ? (string) $this->value['carrier'] : '';
	}

	/**
	 * The order meta of the active carrier plugin.
	 *
	 * @return array Key => value; empty when the carrier is unknown, its plugin is not active
	 *               or the point has no id.
	 */
	public function order_meta() {
		$id = $this->field( 'id' );
		if ( '' === $id || ! $this->plugin_is_active() ) {
			return array();
		}

		$name    = $this->field( 'name' );
		$address = $this->field( 'address' );

		switch ( $this->carrier() ) {
			case 'packeta':
				// wc-zasilkovna's own keys, and Shipmall's, which it reads first.
				return array(
					'_wc_zasilkovna_point_id'   => $id,
					'_wc_zasilkovna_point_name' => $name,
					'ftx_shm_packeta_id'        => $id,
					'ftx_shm_packeta_name'      => $name,
				);

			case 'balikovna':
				// toret-ceska-posta keeps a Balíkovna id with a leading "b".
				$pickup_id = 0 === strpos( $id, 'b' ) ? $id : 'b' . $id;
				$meta      = array(
					'tcp_pickup_id'             => $pickup_id,
					'tcp_pickup_linkid'         => $pickup_id,
					'tcp_pickup_name'           => $name,
					'tcp_pickup_parcelshopname' => $name,
				);
				if ( '' !== $address ) {
					$meta['tcp_pickup_adresa'] = $address;
				}
				return $meta;

			case 'gls':
				// The address comes as one string, so zipcode and city stay empty.
				return array(
					'_gls_id'   => $id,
					'_gls_data' => array(
						'id'      => $id,
						'name'    => $name,
						'address' => $address,
						'zipcode' => '',
						'city'    => '',
					),
				);
		}

		return array();
	}

	/**
	 * The meta to write to wp_postmeta as well: wc-zasilkovna is not HPOS aware and reads
	 * its point from there.
	 *
	 * @return array Key => value.
	 */
	public function post_meta() {
		if ( 'packeta' !== $this->carrier() ) {
			return array();
		}

		return array_intersect_key(
			$this->order_meta(),
			array_flip( array( '_wc_zasilkovna_point_id', '_wc_zasilkovna_point_name' ) )
		);
	}

	/**
	 * Whether the carrier's plugin is active on this site or network-wide.
	 *
	 * @return bool
	 */
	private function plugin_is_active() {
		$directory = self::PLUGINS[ $this->carrier() ] ?? '';
		if ( '' === $directory ) {
			return false;
		}

		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && 0 === strpos( $plugin, $directory . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A string field of the value, trimmed.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private function field( $name ) {
		if ( ! is_array( $this->value ) || ! is_scalar( $this->value[ $name ] ?? null ) ) {
			return '';
		}

		return trim( (string) $this->value[ $name ] );
	}
}
