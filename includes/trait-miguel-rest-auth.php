<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shared bearer-token auth for Miguel REST endpoints.
 *
 * @package Miguel
 */
trait Miguel_Rest_Auth_Trait {

	/**
	 * Validate API access by bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function validate_api_access( $request ) {
		try {
			$provided_token = $this->get_bearer_token( $request );

			if ( '' === $provided_token ) {
				return new WP_Error(
					'auth.token_missing',
					esc_html__( 'Authorization bearer token is missing.', 'miguel' ),
					array( 'status' => 401 )
				);
			}

			$configuration = Miguel_API::getCurrentApiConfiguration();
			if ( false === $configuration ) {
				return new WP_Error(
					'auth.configuration_missing',
					esc_html__( 'Miguel API configuration is not set.', 'miguel' ),
					array( 'status' => 500 )
				);
			}

			$configured_token = isset( $configuration['token'] ) ? (string) $configuration['token'] : '';
			if ( '' === $configured_token ) {
				return new WP_Error(
					'auth.token_not_configured',
					esc_html__( 'Miguel API key is not configured.', 'miguel' ),
					array( 'status' => 500 )
				);
			}

			if ( ! hash_equals( $configured_token, $provided_token ) ) {
				return new WP_Error(
					'auth.token_invalid',
					esc_html__( 'Invalid API token.', 'miguel' ),
					array( 'status' => 403 )
				);
			}

			return true;
		} catch ( Exception $exception ) {
			Miguel::log( 'validate_api_access failed: ' . $exception->getMessage(), 'error' );

			return new WP_Error(
				'auth.unexpected_error',
				esc_html__( 'Unexpected authentication error.', 'miguel' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Read bearer token from Authorization header.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private function get_bearer_token( $request ) {
		$authorization = $request->get_header( 'authorization' );

		if ( empty( $authorization ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && isset( $_SERVER['X-HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['X-HTTP_AUTHORIZATION'] );
		}

		if ( empty( $authorization ) && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $header_value ) {
					if ( 0 === strcasecmp( (string) $header_name, 'Authorization' ) ) {
						$authorization = (string) $header_value;
						break;
					}
				}
			}
		}

		if ( empty( $authorization ) && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $header_name => $header_value ) {
					if ( 0 === strcasecmp( (string) $header_name, 'Authorization' ) ) {
						$authorization = (string) $header_value;
						break;
					}
				}
			}
		}

		if ( empty( $authorization ) ) {
			return '';
		}

		if ( preg_match( '/Bearer\s+(.*)$/i', $authorization, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}
}
