<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 OrderAddressModel value object (all fields nullable).
 *
 * @package Miguel
 */
class Miguel_V2_Order_Address {

	const KEYS = array( 'fullName', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone' );

	/** @var array */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param array $fields Associative array keyed by the v2 field names. Empty
	 *                      strings and missing keys normalize to null.
	 */
	public function __construct( array $fields = array() ) {
		$this->fields = array();
		foreach ( self::KEYS as $key ) {
			$value = isset( $fields[ $key ] ) ? $fields[ $key ] : null;
			if ( null === $value || '' === $value ) {
				$this->fields[ $key ] = null;
			} else {
				$this->fields[ $key ] = (string) $value;
			}
		}
	}

	/**
	 * Whether every field is null (used to omit empty addresses).
	 *
	 * @return bool
	 */
	public function is_empty() {
		foreach ( $this->fields as $value ) {
			if ( null !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->fields;
	}
}
