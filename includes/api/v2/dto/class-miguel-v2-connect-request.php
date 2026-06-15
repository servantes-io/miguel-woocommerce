<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 WooCommerce ConnectRequest value object.
 *
 * @package Miguel
 */
class Miguel_V2_Connect_Request {

	/** @var string */
	private $wc_version;

	/** @var string */
	private $module_version;

	/** @var string */
	private $base_url;

	/** @var string */
	private $base_uri;

	/**
	 * Constructor.
	 *
	 * @param string $wc_version     WooCommerce version.
	 * @param string $module_version Plugin version.
	 * @param string $base_url       Absolute shop base URL.
	 * @param string $base_uri       Canonical base URI path.
	 */
	public function __construct( $wc_version, $module_version, $base_url, $base_uri ) {
		$this->wc_version     = (string) $wc_version;
		$this->module_version = (string) $module_version;
		$this->base_url       = (string) $base_url;
		$this->base_uri       = (string) $base_uri;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'wcVersion'     => $this->wc_version,
			'moduleVersion' => $this->module_version,
			'baseUrl'       => $this->base_url,
			'baseUri'       => $this->base_uri,
		);
	}
}
