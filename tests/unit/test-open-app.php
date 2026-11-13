<?php
/**
 * Tests for Miguel_Open_App: the `?miguel-open-app=web|app` address (SMP-24).
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Open_App extends Miguel_Test_Case {

	const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
	const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

	const OPEN_URL_TOKEN = 'https://mobile.miguel.test/api/v2/cfa/library/open?appId=melvil&token=ulr-secret';
	const APP_URL_TOKEN  = 'melvilella://library?token=ulr-secret';
	const OPEN_URL       = 'https://mobile.miguel.test/api/v2/cfa/library/open?appId=melvil';
	const APP_URL        = 'melvilella://library';

	/** @var array|null */
	private $response;

	/** @var int */
	private $responses;

	public function setUp(): void {
		parent::setUp();
		delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
		delete_option( Miguel_Open_App::ENABLED_OPTION );
		delete_option( Miguel_Open_App::FALLBACK_URL_OPTION );
		unset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
		$_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
		$this->response  = null;
		$this->responses = 0;
	}

	public function tearDown(): void {
		unset( $_GET[ Miguel_Open_App::QUERY_VAR ], $_SERVER['HTTP_USER_AGENT'] );
		remove_shortcode( Miguel_Open_App::SHORTCODE );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function open_app() {
		return new Miguel_Open_App(
			new Miguel_Hook_Manager(),
			new Miguel_V2_Client( 'https://miguel.test', 'shop-key' ),
			function ( $response ) {
				$this->response = $response;
				++$this->responses;
			}
		);
	}

	/**
	 * Request `?miguel-open-app=<mode>` and return what the handler responded.
	 *
	 * Every response, whatever the case, must forbid caching and keep the token out of its body.
	 */
	private function visit( $mode ) {
		$_GET[ Miguel_Open_App::QUERY_VAR ] = $mode;
		$this->open_app()->maybe_handle();

		$this->assertSame( 1, $this->responses, 'the handler answers exactly once' );
		$this->assertStringContainsString( 'no-store', $this->response['headers']['Cache-Control'] );
		$this->assertStringNotContainsString( 'ulr-', $this->response['body'], 'the token is never in HTML' );

		return $this->response;
	}

	private function enable() {
		update_option( Miguel_Open_App::ENABLED_OPTION, 'yes' );
	}

	private function sign_in( $email = 'reader@example.com' ) {
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => $email ) ) );
	}

	private function miguel_answers( $with_token = true ) {
		Miguel_Helper_HTTP::mock_api_responses(
			array(
				'app-login-links' => array(
					'body'     => wp_json_encode(
						array(
							'openUrl'   => $with_token ? self::OPEN_URL_TOKEN : self::OPEN_URL,
							'appUrl'    => $with_token ? self::APP_URL_TOKEN : self::APP_URL,
							'expiresAt' => $with_token ? '2026-11-13T10:30:00Z' : null,
						)
					),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				),
			)
		);
	}

	private function miguel_fails( $response ) {
		Miguel_Helper_HTTP::mock_api_responses( array( 'app-login-links' => $response ) );
	}

	/**
	 * Bodies of the calls made to `POST /v2/app-login-links`.
	 */
	private function login_link_requests() {
		$requests = array();
		foreach ( Miguel_Helper_HTTP::get_requests() as $request ) {
			if ( false !== strpos( $request['url'], 'v2/app-login-links' ) ) {
				$requests[] = $request;
			}
		}
		return $requests;
	}

	private function only_requested_email() {
		$requests = $this->login_link_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://miguel.test/v2/app-login-links', $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( 'Bearer shop-key', $requests[0]['headers']['Authorization'] );

		$body = json_decode( $requests[0]['body'], true );
		$this->assertArrayHasKey( 'email', $body );
		return $body['email'];
	}

	private function report_codes() {
		return array_column( array_column( Miguel_Error_Reporter::get_buffer(), 'report' ), 'code' );
	}

	private function assert_redirect( $location, $response ) {
		$this->assertSame( 302, $response['status'] );
		$this->assertSame( $location, $response['headers']['Location'] );
	}

	private function assert_failure_page( $response ) {
		$this->assertArrayNotHasKey( 'Location', $response['headers'], 'nothing goes back to the app' );
		$this->assertStringContainsString( __( 'Sign-in failed', 'miguel' ), $response['body'] );
	}

	// Criterion 1.

	public function test_web_signed_in_mobile_customer_lands_on_open_url_with_token_for_their_email(): void {
		$this->enable();
		$this->sign_in( 'reader@example.com' );
		$this->miguel_answers();

		$response = $this->visit( 'web' );

		$this->assertSame( 'reader@example.com', $this->only_requested_email() );
		$this->assert_redirect( self::OPEN_URL_TOKEN, $response );
	}

	// Criterion 2.

	public function test_web_signed_out_visitor_lands_on_token_free_open_url(): void {
		$this->enable();
		$this->miguel_answers( false );

		$response = $this->visit( 'web' );

		$this->assertNull( $this->only_requested_email() );
		$this->assert_redirect( self::OPEN_URL, $response );
	}

	public function test_web_desktop_customer_lands_on_token_free_open_url(): void {
		$this->enable();
		$this->sign_in();
		$_SERVER['HTTP_USER_AGENT'] = self::DESKTOP;
		$this->miguel_answers( false );

		$response = $this->visit( 'web' );

		$this->assertNull( $this->only_requested_email() );
		$this->assert_redirect( self::OPEN_URL, $response );
	}

	public function test_web_unreachable_miguel_lands_on_fallback_and_is_reported(): void {
		$this->enable();
		$this->sign_in();
		update_option( Miguel_Open_App::FALLBACK_URL_OPTION, 'https://www.melvil.cz/aplikace-melvil/' );
		$this->miguel_fails( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$response = $this->visit( 'web' );

		$this->assert_redirect( 'https://www.melvil.cz/aplikace-melvil/', $response );
		$this->assertSame( array( 'MIGUEL_UNREACHABLE' ), $this->report_codes() );
	}

	public function test_web_miguel_error_status_lands_on_fallback_and_is_reported(): void {
		$this->enable();
		update_option( Miguel_Open_App::FALLBACK_URL_OPTION, 'https://www.melvil.cz/aplikace-melvil/' );
		$this->miguel_fails( array( 'body' => '', 'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ) ) );

		$response = $this->visit( 'web' );

		$this->assert_redirect( 'https://www.melvil.cz/aplikace-melvil/', $response );
		$this->assertSame( array( 'MIGUEL_HTTP_ERROR' ), $this->report_codes() );
	}

	public function test_web_answer_without_open_url_lands_on_fallback_and_is_reported_without_the_token(): void {
		$this->enable();
		$this->sign_in();
		update_option( Miguel_Open_App::FALLBACK_URL_OPTION, 'https://www.melvil.cz/aplikace-melvil/' );
		$this->miguel_fails(
			array(
				'body'     => wp_json_encode( array( 'appUrl' => self::APP_URL_TOKEN ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			)
		);

		$response = $this->visit( 'web' );

		$this->assert_redirect( 'https://www.melvil.cz/aplikace-melvil/', $response );
		$this->assertSame( array( 'MIGUEL_RESPONSE_INVALID' ), $this->report_codes() );
		$this->assertStringNotContainsString( 'ulr-', wp_json_encode( Miguel_Error_Reporter::get_buffer() ) );
	}

	public function test_web_without_a_fallback_lands_on_the_home_page(): void {
		$this->enable();
		$this->miguel_fails( array( 'body' => '', 'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ) ) );

		$response = $this->visit( 'web' );

		$this->assert_redirect( home_url( '/' ), $response );
	}

	// Criteria 2 and 5: switched off.

	public function test_web_is_off_by_default_and_never_sends_the_email(): void {
		$this->sign_in();
		$this->miguel_answers( false );

		$response = $this->visit( 'web' );

		$this->assertNull( $this->only_requested_email() );
		$this->assert_redirect( self::OPEN_URL, $response );
	}

	public function test_app_switched_off_shows_failure_page_and_never_asks_miguel(): void {
		$this->sign_in();
		$this->miguel_answers();

		$response = $this->visit( 'app' );

		$this->assertSame( array(), $this->login_link_requests() );
		$this->assert_failure_page( $response );
	}

	// Criterion 3.

	public function test_app_signed_out_goes_through_shop_login_and_back(): void {
		$this->enable();
		$this->miguel_answers();

		$response = $this->visit( 'app' );

		$this->assertSame( array(), $this->login_link_requests() );
		$this->assert_redirect( wp_login_url( home_url( '/?miguel-open-app=app' ) ), $response );
	}

	public function test_app_signed_in_customer_is_redirected_to_app_url(): void {
		$this->enable();
		$this->sign_in( 'reader@example.com' );
		$_SERVER['HTTP_USER_AGENT'] = self::DESKTOP;
		$this->miguel_answers();

		$response = $this->visit( 'app' );

		$this->assertSame( 'reader@example.com', $this->only_requested_email() );
		$this->assert_redirect( self::APP_URL_TOKEN, $response );
	}

	public function test_app_miguel_error_shows_failure_page(): void {
		$this->enable();
		$this->sign_in();
		$this->miguel_fails( array( 'body' => '', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ) ) );

		$response = $this->visit( 'app' );

		$this->assert_failure_page( $response );
		$this->assertSame( array( 'MIGUEL_AUTH_REJECTED' ), $this->report_codes() );
	}

	/**
	 * A constant cannot be undefined again, so only a fresh process shows that the handler defines it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_page_cache_plugins_are_told_not_to_cache_it(): void {
		$this->miguel_answers( false );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->visit( 'web' );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	// The address itself.

	public function test_other_requests_are_left_alone(): void {
		$this->enable();
		$this->open_app()->maybe_handle();

		$_GET[ Miguel_Open_App::QUERY_VAR ] = 'something-else';
		$this->open_app()->maybe_handle();

		$this->assertSame( 0, $this->responses );
		$this->assertSame( array(), $this->login_link_requests() );
	}

	public function test_handler_runs_on_the_front_end_before_the_template(): void {
		$hook_manager = new Miguel_Hook_Manager();
		$open_app     = new Miguel_Open_App( $hook_manager, new Miguel_V2_Client( 'https://miguel.test', 'shop-key' ) );

		$open_app->register_hooks();

		$this->assertTrue( $hook_manager->is_hook_registered( 'template_redirect', array( $open_app, 'maybe_handle' ) ) );
		$hook_manager->remove_all_hooks();
	}

	public function test_theme_gets_the_web_address_from_a_function_and_a_shortcode(): void {
		$hook_manager = new Miguel_Hook_Manager();
		( new Miguel_Open_App( $hook_manager, new Miguel_V2_Client( 'https://miguel.test', 'shop-key' ) ) )->register_hooks();

		$this->assertSame( home_url( '/?miguel-open-app=web' ), miguel_open_app_url() );
		$this->assertSame( esc_url( home_url( '/?miguel-open-app=web' ) ), do_shortcode( '[' . Miguel_Open_App::SHORTCODE . ']' ) );
		$hook_manager->remove_all_hooks();
	}
}
