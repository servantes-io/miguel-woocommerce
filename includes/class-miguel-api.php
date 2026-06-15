<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * API client
 *
 * @package Miguel
 */
class Miguel_API {

	const ENV_PROD = 'prod';
	const ENV_STAGING = 'staging';
	const ENV_TEST = 'test';
	const ENV_OWN = 'own';
	const CURRENT_ENV = self::ENV_PROD;

	const API_KEY_OPTION = 'miguel_api_key';
	const SERVER_OPTION = 'miguel_api_server';

	const MIGUEL_API_BASE_URL = 'https://miguel.servantes.cz';

	/**
	 * Get default values for API configuration options.
	 *
	 * @return array
	 */
	public static function getDefaultValues() {
		return array(
			self::API_KEY_OPTION => '',
		);
	}

	/**
	 * Get currently selected API environment.
	 *
	 * @return string
	 */
	public static function getServer() {
		return (string) get_option( self::SERVER_OPTION, self::ENV_PROD );
	}

	/**
	 * Return server URL for given environment.
	 *
	 * @param string $env Environment code.
	 * @return string|false
	 */
	public static function getServerUrl( $env ) {
		switch ( $env ) {
			case self::ENV_PROD:
				return 'https://miguel.servantes.cz';
			case self::ENV_STAGING:
				return 'https://miguel-staging.servantes.cz';
			case self::ENV_TEST:
				return 'https://miguel-test.servantes.cz';
		}

		return false;
	}

	/**
	 * Get API token for selected environment.
	 *
	 * @param string $environment Environment code.
	 * @return string
	 */
	public static function getServerToken( $environment ) {
		return get_option( self::API_KEY_OPTION );
	}

	/**
	 * Check whether API integration is enabled.
	 *
	 * @return bool
	 */
	public static function getEnabled() {
		return '' !== trim( (string) get_option( self::API_KEY_OPTION, '' ) );
	}

	/**
	 * Set API integration enable flag.
	 *
	 * @param bool $enabled Enable state.
	 */
	public static function setEnabled( $enabled ) {
		return;
	}

	/**
	 * Get current API configuration.
	 *
	 * @return array|false
	 */
	public static function getCurrentApiConfiguration() {
		$environment = self::getServer();
		$url = self::getServerUrl( $environment );
		$token = self::getServerToken( $environment );

		if ( false === $url || false === $token ) {
			return false;
		}

		$url = trim( (string) $url );
		$token = trim( (string) $token );

		if ( '' === $url || '' === $token ) {
			return false;
		}

		return array(
			'url' => untrailingslashit( $url ),
			'token' => $token,
			'environment' => $environment,
		);
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return array
	 */
	public static function get_default_values() {
		return self::getDefaultValues();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return string
	 */
	public static function get_environment() {
		return self::getServer();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param string $environment Environment code.
	 * @return string|false
	 */
	public static function get_server_url( $environment = '' ) {
		$environment = '' === $environment ? self::getServer() : $environment;

		return self::getServerUrl( $environment );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param string $environment Environment code.
	 * @return string|false
	 */
	public static function get_server_token( $environment = '' ) {
		$environment = '' === $environment ? self::getServer() : $environment;

		return self::getServerToken( $environment );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return bool
	 */
	public static function get_enabled() {
		return self::getEnabled();
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @param bool $enabled Enable state.
	 */
	public static function set_enabled( $enabled ) {
		self::setEnabled( $enabled );
	}

	/**
	 * Backward-compatible wrapper.
	 *
	 * @return array|false
	 */
	public static function get_current_api_configuration() {
		return self::getCurrentApiConfiguration();
	}
}
