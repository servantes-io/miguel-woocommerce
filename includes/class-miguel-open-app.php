<?php
/**
 * The address that opens the app with the shop's customer signed in
 *
 * @package Miguel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * `<shop>/?miguel-open-app=web|app` (SERGEN-33, contract point 5).
 *
 * `web` is the theme's "read in the app" button: it redirects to the open page Miguel names, with a
 * one-time sign-in only for a signed-in customer on a phone. `app` is the app's sign-in window: it
 * routes a signed-out visitor through the shop's login and redirects a signed-in one into the app.
 *
 * Handled on the front end, where WordPress's login cookie applies (the REST API ignores it without
 * a nonce). The token only ever travels in a redirect's Location header, and no response may be
 * cached: every one carries the no-cache header of each cache that may sit in front of WordPress
 * (see `NO_CACHE_HEADERS`), and `DONOTCACHEPAGE` for page-cache plugins.
 *
 * @package Miguel
 */
class Miguel_Open_App {

	const QUERY_VAR           = 'miguel-open-app';
	const SHORTCODE           = 'miguel_open_app_url';
	const ENABLED_OPTION      = 'miguel_open_app_enabled';
	const FALLBACK_URL_OPTION = 'miguel_open_app_fallback_url';

	/**
	 * Sent with every response, each in the header its cache reads: browsers and shared proxies
	 * (Cache-Control, Expires, Pragma for HTTP/1.0 ones), CDNs (CDN-Cache-Control per RFC 9213,
	 * Cloudflare's own, Surrogate-Control for Fastly, Akamai and Varnish), nginx's FastCGI and proxy
	 * cache (X-Accel-Expires, which it reads even where a host ignores Cache-Control) and LiteSpeed's
	 * server cache. A cache configured to ignore all of them is the host's to exclude this address.
	 */
	const NO_CACHE_HEADERS = array(
		'Cache-Control'                => 'no-store, no-cache, must-revalidate, max-age=0, private',
		'Expires'                      => 'Wed, 11 Jan 1984 05:00:00 GMT',
		'Pragma'                       => 'no-cache',
		'CDN-Cache-Control'            => 'no-store',
		'Cloudflare-CDN-Cache-Control' => 'no-store',
		'Surrogate-Control'            => 'no-store',
		'X-Accel-Expires'              => '0',
		'X-LiteSpeed-Cache-Control'    => 'no-cache',
	);

	/**
	 * Hook manager instance
	 *
	 * @var Miguel_Hook_Manager_Interface
	 */
	private Miguel_Hook_Manager_Interface $hook_manager;

	/**
	 * v2 client instance
	 *
	 * @var Miguel_V2_Client
	 */
	private Miguel_V2_Client $client;

	/**
	 * Sends a response ({ status, headers, body }) and ends the request.
	 *
	 * @var callable
	 */
	private $responder;

	/**
	 * Constructor.
	 *
	 * @param Miguel_Hook_Manager_Interface $hook_manager Hook manager for registering actions.
	 * @param Miguel_V2_Client              $client       v2 client.
	 * @param callable|null                 $responder    Sends a response (default: headers, body, exit).
	 */
	public function __construct( Miguel_Hook_Manager_Interface $hook_manager, Miguel_V2_Client $client, $responder = null ) {
		$this->hook_manager = $hook_manager;
		$this->client       = $client;
		$this->responder    = $responder ? $responder : array( $this, 'send' );
	}

	/**
	 * Register WordPress hooks
	 */
	public function register_hooks() {
		// Before any other template_redirect callback can redirect or start output.
		$this->hook_manager->add_action( 'template_redirect', array( $this, 'maybe_handle' ), 0 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * The address for one mode. It never carries a token.
	 *
	 * @param string $mode `web` or `app`.
	 * @return string
	 */
	public static function url( $mode ) {
		return add_query_arg( self::QUERY_VAR, $mode, home_url( '/' ) );
	}

	/**
	 * `[miguel_open_app_url]`: the address of the theme's button.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return esc_url( self::url( 'web' ) );
	}

	/**
	 * Answer `?miguel-open-app=web|app`; leave every other request alone.
	 */
	public function maybe_handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link anyone may open; it changes nothing.
		$mode = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( 'web' !== $mode && 'app' !== $mode ) {
			return;
		}

		// For page-cache plugins: never store this request's answer.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$response = 'web' === $mode ? $this->web() : $this->app();
		call_user_func( $this->responder, $response );
	}

	/**
	 * The theme's button. Always leads somewhere.
	 *
	 * @return array
	 */
	private function web() {
		$email  = $this->is_enabled() && wp_is_mobile() ? $this->customer_email() : null;
		$result = $this->client->create_app_login_link( $email );
		if ( is_wp_error( $result ) ) {
			// The client has reported it.
			return $this->redirect( $this->fallback_url() );
		}

		return $this->redirect( $result['openUrl'] );
	}

	/**
	 * The app's sign-in window.
	 *
	 * @return array
	 */
	private function app() {
		if ( ! $this->is_enabled() ) {
			return $this->failure_page();
		}

		if ( ! is_user_logged_in() ) {
			return $this->redirect( wp_login_url( self::url( 'app' ) ) );
		}

		$email = $this->customer_email();
		if ( null === $email ) {
			return $this->failure_page();
		}

		$result = $this->client->create_app_login_link( $email );
		if ( is_wp_error( $result ) ) {
			return $this->failure_page();
		}

		return $this->redirect( $result['appUrl'] );
	}

	/**
	 * Whether the shop switched the sign-in on. Off by default: only Melvil's key may issue one.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return 'yes' === get_option( self::ENABLED_OPTION, 'no' );
	}

	/**
	 * The signed-in user's email, or null.
	 *
	 * @return string|null
	 */
	private function customer_email() {
		$user = wp_get_current_user();
		return $user->exists() && '' !== (string) $user->user_email ? (string) $user->user_email : null;
	}

	/**
	 * Where the button leads when Miguel does not answer: the configured page, else the home page.
	 *
	 * @return string
	 */
	private function fallback_url() {
		$url = esc_url_raw( trim( (string) get_option( self::FALLBACK_URL_OPTION, '' ) ) );
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * A redirect nothing may cache.
	 *
	 * @param string $location Absolute URL, an app link included.
	 * @return array
	 */
	private function redirect( $location ) {
		return array(
			'status'  => 302,
			'headers' => self::NO_CACHE_HEADERS + array( 'Location' => wp_sanitize_redirect( $location ) ),
			'body'    => '',
		);
	}

	/**
	 * The short page the app's sign-in window shows when the sign-in failed. Static text: nothing in
	 * it comes from Miguel or the request.
	 *
	 * @return array
	 */
	private function failure_page() {
		$title = esc_html__( 'Sign-in failed', 'miguel' );
		$body  = '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . $title . '</title></head><body><h1>' . $title . '</h1><p>'
			. esc_html__( 'Close this window and try again.', 'miguel' ) . '</p></body></html>';

		return array(
			'status'  => 200,
			'headers' => self::NO_CACHE_HEADERS + array( 'Content-Type' => 'text/html; charset=utf-8' ),
			'body'    => $body,
		);
	}

	/**
	 * Send a response and end the request.
	 *
	 * @param array $response { status, headers, body }.
	 */
	private function send( $response ) {
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		if ( '' !== $response['body'] ) {
			echo '<!DOCTYPE html>';
			echo wp_kses(
				$response['body'],
				array(
					'html'  => array( 'lang' => true ),
					'head'  => array(),
					'meta'  => array(
						'charset' => true,
						'name'    => true,
						'content' => true,
					),
					'title' => array(),
					'body'  => array(),
					'h1'    => array(),
					'p'     => array(),
				)
			);
		}
		exit;
	}
}
