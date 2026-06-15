<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * v2 WatermarkUser value object.
 *
 * @package Miguel
 */
class Miguel_V2_Watermark_User {

	/** @var string|null */
	private $id;

	/** @var string|null */
	private $name;

	/** @var string|null */
	private $address;

	/** @var string */
	private $email;

	/** @var string */
	private $language;

	/**
	 * Constructor.
	 *
	 * @param string      $email    Required user email.
	 * @param string      $language Required user language.
	 * @param string|null $id       E-shop user id (null for guests).
	 * @param string|null $name     Full name.
	 * @param string|null $address  Address string.
	 */
	public function __construct( $email, $language, $id = null, $name = null, $address = null ) {
		$this->email    = (string) $email;
		$this->language = (string) $language;
		$this->id       = ( null === $id ) ? null : (string) $id;
		$this->name     = ( null === $name ) ? null : (string) $name;
		$this->address  = ( null === $address ) ? null : (string) $address;
	}

	/**
	 * Serialize to the v2 JSON shape.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'       => $this->id,
			'name'     => $this->name,
			'address'  => $this->address,
			'email'    => $this->email,
			'language' => $this->language,
		);
	}
}
