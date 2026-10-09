<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reports the errors the plugin catches to Miguel (`POST /v2/eshop/errors`), which forwards them to GlitchTip.
 *
 * The contract is Miguel's `docs/client-errors.md`. Reports wait in one WP option and are sent from WP-Cron,
 * never in a customer's request. Nothing here may throw into its caller, and a failure to send is never itself
 * reported.
 *
 * @package Miguel
 */
class Miguel_Error_Reporter {

	/**
	 * Option holding the buffered reports, oldest first. Each entry is { id, report }.
	 */
	const BUFFER_OPTION = 'miguel_error_reports';

	/**
	 * Option holding the time before which nothing is sent (set by a 429).
	 */
	const RETRY_AT_OPTION = 'miguel_error_reports_retry_at';

	/**
	 * Hourly WP-Cron hook that sends the buffer.
	 */
	const CRON_HOOK = 'miguel_flush_error_reports';

	/**
	 * WP-Cron hook of the one-off flush after a successful call. Its own hook, so a pending one-off never hides
	 * the hourly event from schedule_periodic_flush().
	 */
	const FLUSH_SOON_HOOK = 'miguel_flush_error_reports_soon';

	const BUFFER_MAX      = 50;
	const BATCH_MAX       = 20;
	const BATCH_MAX_BYTES = 60000;
	const TIMEOUT         = 5;
	const RETRY_DEFAULT   = 60;

	const MESSAGE_MAX       = 1024;
	const OPERATION_MAX     = 200;
	const EXCERPT_MAX       = 500;
	const CONTEXT_MAX       = 20;
	const CONTEXT_KEY_MAX   = 64;
	const CONTEXT_VALUE_MAX = 256;

	/**
	 * Buffer one report. Never throws.
	 *
	 * @param string $code    Error code, `^[A-Z][A-Z0-9_]{2,63}$`.
	 * @param string $message What happened.
	 * @param array  $details Optional `operation`, `httpStatus`, `responseExcerpt` and `context` (ids only).
	 */
	public static function report( $code, $message, $details = array() ) {
		try {
			if ( ! preg_match( '/^[A-Z][A-Z0-9_]{2,63}$/', (string) $code ) ) {
				return;
			}

			$buffer   = self::get_buffer();
			$buffer[] = array(
				'id'     => wp_generate_uuid4(),
				'report' => self::build( $code, $message, $details ),
			);

			update_option( self::BUFFER_OPTION, array_slice( $buffer, -self::BUFFER_MAX ), false );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * The buffered reports, oldest first.
	 *
	 * @return array
	 */
	public static function get_buffer() {
		$buffer = get_option( self::BUFFER_OPTION, array() );

		return is_array( $buffer ) ? array_values( $buffer ) : array();
	}

	/**
	 * Schedule a flush when something is buffered. Called after a successful Miguel call: the send itself runs
	 * in WP-Cron, not in the request that made the call.
	 */
	public static function schedule_flush() {
		try {
			if ( array() === self::get_buffer() ) {
				return;
			}

			wp_schedule_single_event( time(), self::FLUSH_SOON_HOOK );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Keep the periodic flush scheduled.
	 */
	public static function schedule_periodic_flush() {
		try {
			if ( false === wp_get_schedule( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Remove the scheduled flushes when the plugin is deactivated.
	 */
	public static function deactivate() {
		try {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_clear_scheduled_hook( self::FLUSH_SOON_HOOK );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Send the oldest buffered reports. Never throws, and reports nothing about its own failure.
	 */
	public static function flush() {
		try {
			self::send_batch();
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Send one batch and apply Miguel's answer to the buffer.
	 */
	private static function send_batch() {
		if ( time() < (int) get_option( self::RETRY_AT_OPTION, 0 ) ) {
			return;
		}

		$url = Miguel_API::getServerUrl( Miguel_API::getServer() );
		if ( false === $url ) {
			return;
		}

		$batch = self::take_batch( self::get_buffer() );
		if ( array() === $batch['ids'] ) {
			return;
		}

		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		$key     = trim( (string) get_option( Miguel_API::API_KEY_OPTION, '' ) );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_post(
			untrailingslashit( $url ) . '/v2/eshop/errors',
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => Miguel_V2_Client::user_agent(),
				'headers'    => $headers,
				'body'       => $batch['body'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 === $status || 400 === $status ) {
			self::remove( $batch['ids'] );
		} elseif ( 429 === $status ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$delay       = is_numeric( $retry_after ) && (int) $retry_after > 0 ? (int) $retry_after : self::RETRY_DEFAULT;
			update_option( self::RETRY_AT_OPTION, time() + $delay, false );
		}
	}

	/**
	 * The oldest reports that fit one request: at most BATCH_MAX of them and BATCH_MAX_BYTES of body.
	 *
	 * @param array $buffer Buffered entries.
	 * @return array { ids, body }
	 */
	private static function take_batch( $buffer ) {
		$ids     = array();
		$reports = array();
		$body    = '';

		foreach ( array_slice( $buffer, 0, self::BATCH_MAX ) as $entry ) {
			$candidate = wp_json_encode( array( 'reports' => array_merge( $reports, array( $entry['report'] ) ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $candidate || ( array() !== $ids && strlen( $candidate ) > self::BATCH_MAX_BYTES ) ) {
				break;
			}

			$ids[]     = $entry['id'];
			$reports[] = $entry['report'];
			$body      = $candidate;
		}

		return array(
			'ids'  => $ids,
			'body' => $body,
		);
	}

	/**
	 * Remove the answered reports, re-reading the buffer: another request may have added to it meanwhile.
	 *
	 * @param array $ids Ids of the sent entries.
	 */
	private static function remove( $ids ) {
		// get_option() would answer from this request's cache, which has not seen the other requests' writes.
		wp_cache_delete( self::BUFFER_OPTION, 'options' );
		$remaining = array_filter(
			self::get_buffer(),
			function ( $entry ) use ( $ids ) {
				return ! in_array( $entry['id'], $ids, true );
			}
		);

		update_option( self::BUFFER_OPTION, array_values( $remaining ), false );
	}

	/**
	 * Build a report within the contract's limits.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param array  $details Optional fields.
	 * @return array
	 */
	private static function build( $code, $message, $details ) {
		$message = self::truncate( $message, self::MESSAGE_MAX );
		$report  = array(
			'code'       => (string) $code,
			'message'    => '' === trim( $message ) ? (string) $code : $message,
			'occurredAt' => gmdate( 'c' ),
		);

		if ( isset( $details['operation'] ) && '' !== (string) $details['operation'] ) {
			$report['operation'] = self::truncate( $details['operation'], self::OPERATION_MAX );
		}

		if ( isset( $details['httpStatus'] ) && (int) $details['httpStatus'] >= 100 && (int) $details['httpStatus'] <= 599 ) {
			$report['httpStatus'] = (int) $details['httpStatus'];
		}

		if ( isset( $details['responseExcerpt'] ) && '' !== (string) $details['responseExcerpt'] ) {
			$report['responseExcerpt'] = self::truncate( $details['responseExcerpt'], self::EXCERPT_MAX );
		}

		if ( isset( $details['context'] ) && is_array( $details['context'] ) ) {
			$context = array();
			foreach ( $details['context'] as $key => $value ) {
				if ( count( $context ) >= self::CONTEXT_MAX ) {
					break;
				}
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$context[ self::truncate( $key, self::CONTEXT_KEY_MAX ) ] = self::truncate( $value, self::CONTEXT_VALUE_MAX );
			}

			if ( array() !== $context ) {
				$report['context'] = $context;
			}
		}

		return $report;
	}

	/**
	 * Cut a value to a length, dropping invalid UTF-8 first. The length is counted in UTF-16 code units, as
	 * Miguel (.NET) counts it: a character outside the Basic Multilingual Plane counts twice.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length.
	 * @return string
	 */
	private static function truncate( $value, $max ) {
		$value = mb_substr( wp_check_invalid_utf8( (string) $value, true ), 0, $max );
		while ( strlen( mb_convert_encoding( $value, 'UTF-16LE', 'UTF-8' ) ) / 2 > $max ) {
			$value = mb_substr( $value, 0, -1 );
		}

		return $value;
	}
}
