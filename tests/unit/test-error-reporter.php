<?php
/**
 * Tests for Miguel_Error_Reporter.
 *
 * @package Miguel\Tests
 */
class Miguel_Test_Error_Reporter extends WP_UnitTestCase {

	/**
	 * Requests seen by the HTTP stub.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * What the HTTP stub answers: an array response or a WP_Error.
	 *
	 * @var array|WP_Error
	 */
	private $answer;

	/**
	 * Called while a request is in flight, to act as another process would.
	 *
	 * @var callable|null
	 */
	private $during_send = null;

	public function setUp(): void {
		parent::setUp();
		$this->requests = array();
		$this->answer   = array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 202, 'message' => 'Accepted' ),
		);
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
		update_option( Miguel_API::API_KEY_OPTION, 'key-123' );
		update_option( Miguel_API::SERVER_OPTION, Miguel_API::ENV_TEST );
		delete_option( Miguel_Error_Reporter::BUFFER_OPTION );
		delete_option( Miguel_Error_Reporter::RETRY_AT_OPTION );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		wp_clear_scheduled_hook( Miguel_Error_Reporter::CRON_HOOK );
		wp_clear_scheduled_hook( Miguel_Error_Reporter::FLUSH_SOON_HOOK );
		parent::tearDown();
	}

	public function stub_http( $response, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( null !== $this->during_send ) {
			call_user_func( $this->during_send );
		}

		return $this->answer;
	}

	private function buffer() {
		return Miguel_Error_Reporter::get_buffer();
	}

	private function sent_reports( $index = 0 ) {
		$body = json_decode( $this->requests[ $index ]['args']['body'], true );
		return $body['reports'];
	}

	private function fill( $count, $message = 'm' ) {
		for ( $i = 0; $i < $count; $i++ ) {
			Miguel_Error_Reporter::report( 'TEST_CODE', $message . $i );
		}
	}

	public function test_report_stores_code_message_time_and_details(): void {
		Miguel_Error_Reporter::report(
			'MIGUEL_HTTP_ERROR',
			'Conflict',
			array(
				'operation'       => 'POST /v2/orders',
				'httpStatus'      => 409,
				'responseExcerpt' => '{"title":"Conflict"}',
				'context'         => array( 'orderId' => '12' ),
			)
		);

		$buffer = $this->buffer();
		$this->assertCount( 1, $buffer );
		$report = $buffer[0]['report'];
		$this->assertSame( 'MIGUEL_HTTP_ERROR', $report['code'] );
		$this->assertSame( 'Conflict', $report['message'] );
		$this->assertSame( 'POST /v2/orders', $report['operation'] );
		$this->assertSame( 409, $report['httpStatus'] );
		$this->assertSame( '{"title":"Conflict"}', $report['responseExcerpt'] );
		$this->assertSame( array( 'orderId' => '12' ), $report['context'] );
		$this->assertNotFalse( DateTime::createFromFormat( DateTime::ATOM, $report['occurredAt'] ) );
		$this->assertEqualsWithDelta( time(), strtotime( $report['occurredAt'] ), 5 );
	}

	public function test_report_truncates_fields_to_contract_limits(): void {
		Miguel_Error_Reporter::report(
			'TEST_CODE',
			str_repeat( 'č', 2000 ),
			array(
				'operation'       => str_repeat( 'o', 300 ),
				'responseExcerpt' => str_repeat( 'x', 5000 ),
				'context'         => array( str_repeat( 'k', 80 ) => str_repeat( 'v', 300 ) ),
			)
		);

		$report = $this->buffer()[0]['report'];
		$this->assertSame( 1024, mb_strlen( $report['message'] ) );
		$this->assertSame( 200, strlen( $report['operation'] ) );
		$this->assertLessThanOrEqual( 2048, strlen( $report['responseExcerpt'] ) );
		$key = array_keys( $report['context'] )[0];
		$this->assertSame( 64, strlen( $key ) );
		$this->assertSame( 256, strlen( $report['context'][ $key ] ) );
	}

	public function test_report_keeps_only_scalar_context_values_and_at_most_twenty(): void {
		$context = array(
			'nested' => array( 'email' => 'a@b.cz' ),
			'object' => new stdClass(),
			'number' => 12,
		);
		for ( $i = 0; $i < 30; $i++ ) {
			$context[ 'k' . $i ] = (string) $i;
		}

		Miguel_Error_Reporter::report( 'TEST_CODE', 'm', array( 'context' => $context ) );

		$stored = $this->buffer()[0]['report']['context'];
		$this->assertArrayNotHasKey( 'nested', $stored );
		$this->assertArrayNotHasKey( 'object', $stored );
		$this->assertSame( '12', $stored['number'] );
		$this->assertCount( 20, $stored );
		foreach ( $stored as $value ) {
			$this->assertIsString( $value );
		}
	}

	public function test_report_drops_invalid_http_status(): void {
		Miguel_Error_Reporter::report( 'TEST_CODE', 'm', array( 'httpStatus' => 0 ) );

		$this->assertArrayNotHasKey( 'httpStatus', $this->buffer()[0]['report'] );
	}

	public function test_buffer_never_exceeds_fifty_and_drops_the_oldest(): void {
		$this->fill( 55 );

		$buffer = $this->buffer();
		$this->assertCount( 50, $buffer );
		$this->assertSame( 'm5', $buffer[0]['report']['message'] );
		$this->assertSame( 'm54', $buffer[49]['report']['message'] );
	}

	public function test_report_never_throws(): void {
		add_filter( 'pre_update_option_' . Miguel_Error_Reporter::BUFFER_OPTION, array( $this, 'throw_exception' ) );
		add_filter( 'pre_add_option_' . Miguel_Error_Reporter::BUFFER_OPTION, array( $this, 'throw_exception' ) );

		Miguel_Error_Reporter::report( 'TEST_CODE', 'm' );

		remove_filter( 'pre_update_option_' . Miguel_Error_Reporter::BUFFER_OPTION, array( $this, 'throw_exception' ) );
		remove_filter( 'pre_add_option_' . Miguel_Error_Reporter::BUFFER_OPTION, array( $this, 'throw_exception' ) );
		$this->addToAssertionCount( 1 );
	}

	public function throw_exception() {
		throw new RuntimeException( 'boom' );
	}

	public function test_flush_posts_to_errors_endpoint_with_key_user_agent_and_short_timeout(): void {
		$this->fill( 1 );

		Miguel_Error_Reporter::flush();

		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://miguel-test.servantes.cz/v2/eshop/errors', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertLessThanOrEqual( 5, $request['args']['timeout'] );
		$this->assertSame( 'Bearer key-123', $request['args']['headers']['Authorization'] );
		$this->assertStringStartsWith( 'MiguelForWooCommerce/' . miguel()->version . '; WordPress/', $request['args']['user-agent'] );
		$this->assertStringContainsString( 'application/json', $request['args']['headers']['Content-Type'] );
	}

	public function test_flush_sends_without_authorization_when_there_is_no_key(): void {
		update_option( Miguel_API::API_KEY_OPTION, '' );
		$this->fill( 1 );

		Miguel_Error_Reporter::flush();

		$this->assertCount( 1, $this->requests );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
	}

	public function test_flush_does_nothing_with_an_empty_buffer(): void {
		Miguel_Error_Reporter::flush();

		$this->assertCount( 0, $this->requests );
	}

	public function test_flush_sends_at_most_twenty_reports_with_their_original_time(): void {
		$this->fill( 25 );
		$first = $this->buffer()[0]['report'];

		Miguel_Error_Reporter::flush();

		$sent = $this->sent_reports();
		$this->assertCount( 20, $sent );
		$this->assertSame( $first, $sent[0] );
		$this->assertArrayNotHasKey( 'id', $sent[0] );
	}

	public function test_accepted_removes_only_the_sent_reports(): void {
		$this->fill( 25 );

		Miguel_Error_Reporter::flush();

		$buffer = $this->buffer();
		$this->assertCount( 5, $buffer );
		$this->assertSame( 'm20', $buffer[0]['report']['message'] );
	}

	public function test_bad_request_drops_the_sent_reports(): void {
		$this->answer['response'] = array( 'code' => 400, 'message' => 'Bad Request' );
		$this->fill( 3 );

		Miguel_Error_Reporter::flush();

		$this->assertCount( 0, $this->buffer() );
	}

	public function test_server_error_keeps_the_reports_and_reports_nothing(): void {
		$this->answer['response'] = array( 'code' => 503, 'message' => 'Unavailable' );
		$this->fill( 3 );
		$before = $this->buffer();

		Miguel_Error_Reporter::flush();

		$this->assertSame( $before, $this->buffer() );
	}

	public function test_network_failure_keeps_the_reports_and_reports_nothing(): void {
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$this->fill( 3 );
		$before = $this->buffer();

		Miguel_Error_Reporter::flush();

		$this->assertSame( $before, $this->buffer() );
	}

	public function test_too_many_requests_waits_for_retry_after(): void {
		$this->answer = array(
			'headers'  => array( 'retry-after' => '120' ),
			'body'     => '',
			'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
		);
		$this->fill( 3 );

		Miguel_Error_Reporter::flush();
		Miguel_Error_Reporter::flush();

		$this->assertCount( 1, $this->requests );
		$this->assertCount( 3, $this->buffer() );
		$this->assertEqualsWithDelta( time() + 120, (int) get_option( Miguel_Error_Reporter::RETRY_AT_OPTION ), 5 );

		update_option( Miguel_Error_Reporter::RETRY_AT_OPTION, time() - 1 );
		Miguel_Error_Reporter::flush();

		$this->assertCount( 2, $this->requests );
	}

	public function test_a_batch_stays_under_the_body_limit(): void {
		$context = array();
		for ( $k = 0; $k < 20; $k++ ) {
			$context[ str_repeat( chr( 97 + $k ), 64 ) ] = str_repeat( 'v', 256 );
		}
		for ( $i = 0; $i < 20; $i++ ) {
			Miguel_Error_Reporter::report( 'TEST_CODE', str_repeat( 'm', 1024 ), array( 'context' => $context ) );
		}

		Miguel_Error_Reporter::flush();

		$this->assertLessThanOrEqual( 60000, strlen( $this->requests[0]['args']['body'] ) );
		$sent = count( $this->sent_reports() );
		$this->assertGreaterThan( 0, $sent );
		$this->assertLessThan( 20, $sent );
		$this->assertCount( 20 - $sent, $this->buffer() );
	}

	public function test_a_report_added_by_another_request_during_the_send_survives(): void {
		global $wpdb;
		$this->fill( 2 );
		$this->during_send = function () use ( $wpdb ) {
			$buffer   = $this->buffer();
			$buffer[] = array(
				'id'     => 'from-another-request',
				'report' => array(
					'code'       => 'TEST_CODE',
					'message'    => 'late',
					'occurredAt' => gmdate( 'c' ),
				),
			);
			// Write the row only, as another PHP process would: this process's cache keeps the old value.
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => maybe_serialize( $buffer ) ),
				array( 'option_name' => Miguel_Error_Reporter::BUFFER_OPTION )
			);
		};

		Miguel_Error_Reporter::flush();

		$buffer = $this->buffer();
		$this->assertCount( 1, $buffer );
		$this->assertSame( 'late', $buffer[0]['report']['message'] );
	}

	public function test_lengths_are_counted_in_utf16_units(): void {
		Miguel_Error_Reporter::report( 'TEST_CODE', str_repeat( '😀', 1000 ), array( 'context' => array( 'k' => str_repeat( '😀', 200 ) ) ) );

		$report = $this->buffer()[0]['report'];
		$this->assertLessThanOrEqual( 1024, strlen( mb_convert_encoding( $report['message'], 'UTF-16LE', 'UTF-8' ) ) / 2 );
		$this->assertLessThanOrEqual( 256, strlen( mb_convert_encoding( $report['context']['k'], 'UTF-16LE', 'UTF-8' ) ) / 2 );
		$this->assertSame( 512, mb_strlen( $report['message'] ) );
	}

	public function test_an_empty_message_is_replaced_by_the_code(): void {
		Miguel_Error_Reporter::report( 'TEST_CODE', '  ' );

		$this->assertSame( 'TEST_CODE', $this->buffer()[0]['report']['message'] );
	}

	public function test_a_report_with_an_invalid_code_is_not_buffered(): void {
		Miguel_Error_Reporter::report( 'bad code', 'm' );

		$this->assertCount( 0, $this->buffer() );
	}

	public function test_a_pending_flush_does_not_duplicate_the_hourly_event(): void {
		$this->fill( 1 );
		Miguel_Error_Reporter::schedule_flush();
		Miguel_Error_Reporter::schedule_periodic_flush();

		// Another request, later: its time() differs, so a second hourly event would not land in the same slot.
		$hourly = $this->recurring_timestamps();
		$this->assertCount( 1, $hourly );
		wp_unschedule_event( $hourly[0], Miguel_Error_Reporter::CRON_HOOK );
		wp_schedule_event( $hourly[0] + 600, 'hourly', Miguel_Error_Reporter::CRON_HOOK );
		Miguel_Error_Reporter::schedule_periodic_flush();

		$this->assertCount( 1, $this->recurring_timestamps() );
	}

	private function recurring_timestamps() {
		$timestamps = array();
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks[ Miguel_Error_Reporter::CRON_HOOK ] ?? array() as $event ) {
				if ( false !== $event['schedule'] ) {
					$timestamps[] = $timestamp;
				}
			}
		}
		return $timestamps;
	}

	public function test_schedule_flush_only_when_something_is_buffered(): void {
		Miguel_Error_Reporter::schedule_flush();
		$this->assertFalse( wp_next_scheduled( Miguel_Error_Reporter::FLUSH_SOON_HOOK ) );

		$this->fill( 1 );
		Miguel_Error_Reporter::schedule_flush();
		$this->assertNotFalse( wp_next_scheduled( Miguel_Error_Reporter::FLUSH_SOON_HOOK ) );
	}

	public function test_periodic_flush_is_scheduled_hourly_and_cleared_on_deactivation(): void {
		$this->fill( 1 );
		Miguel_Error_Reporter::schedule_flush();
		Miguel_Error_Reporter::schedule_periodic_flush();

		$this->assertSame( 'hourly', wp_get_schedule( Miguel_Error_Reporter::CRON_HOOK ) );

		Miguel_Error_Reporter::deactivate();

		$this->assertFalse( wp_next_scheduled( Miguel_Error_Reporter::CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Miguel_Error_Reporter::FLUSH_SOON_HOOK ) );
	}
}
