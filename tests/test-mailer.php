<?php
/**
 * Tests for Euromail_Mailer.
 *
 * Guarantees under test:
 * - An unconfigured site never has its mail broken: pre_wp_mail() returns
 *   null so core wp_mail() keeps sending.
 * - A successful backend produces a "sent" log row with the backend name
 *   and message ID, fires wp_mail_succeeded, and wp_mail() returns true.
 * - A failure classified retryable (the default for an unrecognized
 *   Throwable, or Euromail_Retryable_Exception) is put in status
 *   'retrying' with a next_attempt_at in the near future, and still fires
 *   wp_mail_failed / returns false synchronously — we don't yet know
 *   whether the background retry will succeed.
 * - A failure classified permanent (Euromail_Permanent_Exception, or
 *   retries already exhausted) is marked failed immediately.
 * - The stored payload NEVER contains attachment content, at any status:
 *   a retry or a resend re-reads attachments from their source 'path'
 *   instead (Euromail_Queue's job). A successful send's stored payload
 *   additionally respects euromail_store_body (null unless it's on).
 * - Any Throwable a backend raises (not just Exception) is caught: wp_mail()
 *   never fatals.
 * - The backend chain reflects euromail_backend (primary) and
 *   euromail_fallback_enabled (whether the other backend is appended), and
 *   a failing primary falls through to a configured fallback.
 * - A failed log insert doesn't stop the send or fatal; later log updates
 *   are skipped gracefully instead of writing to a nonexistent row.
 * - wp_mail_succeeded fires (with the original wp_mail() args) on success
 *   only, never on failure.
 *
 * These tests never require the euromail/euromail-php SDK for the
 * *behavior* under test: backends are injected as plain fakes via the
 * `euromail_backends` filter. A couple of chain-order tests do rely on the
 * real Euromail_Api_Backend/Euromail_Smtp_Backend classes existing (to
 * observe what the mailer would have built by default) but never actually
 * call out to them.
 *
 * @package Euromail
 */

class Euromail_Test_Fake_Backend {

	private $result;
	private $exception;

	public static function succeeding( $message_id ) {
		$backend         = new self();
		$backend->result = array( 'message_id' => $message_id );
		return $backend;
	}

	/**
	 * @param Throwable $exception Anything the backend should raise —
	 *                             Exception and Error both qualify. Use
	 *                             Euromail_Permanent_Exception to simulate
	 *                             a non-retryable failure,
	 *                             Euromail_Retryable_Exception (or a plain
	 *                             Exception/Error, which defaults to
	 *                             retryable) for a transient one.
	 */
	public static function failing( Throwable $exception ) {
		$backend            = new self();
		$backend->exception = $exception;
		return $backend;
	}

	public function send( array $email, $idempotency_key ) {
		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return $this->result;
	}
}

class Test_Euromail_Mailer extends WP_UnitTestCase {

	private $mailer;

	/**
	 * Temp files created by a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	public function set_up() {
		parent::set_up();
		$this->mailer = new Euromail_Mailer();
	}

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		delete_option( 'euromail_store_body' );
		delete_option( 'euromail_backend' );
		delete_option( 'euromail_fallback_enabled' );
		delete_option( 'euromail_smtp_host' );
		delete_option( 'euromail_smtp_auth' );
		remove_all_filters( 'euromail_backends' );
		remove_all_actions( 'wp_mail_failed' );
		remove_all_actions( 'wp_mail_succeeded' );
		remove_all_filters( 'query' );

		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	private function make_temp_file( $contents ) {
		$path = tempnam( sys_get_temp_dir(), 'euromail-mailer-test-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		return $path;
	}

	private function base_atts() {
		return array(
			'to'          => 'recipient@example.com',
			'subject'     => 'Hello',
			'message'     => 'Body text',
			'headers'     => '',
			'attachments' => array(),
		);
	}

	private function latest_log_row() {
		global $wpdb;

		return $wpdb->get_row(
			'SELECT * FROM ' . Euromail_Logger::table_name() . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);
	}

	public function test_unconfigured_site_returns_null_and_writes_no_log_row() {
		delete_option( 'euromail_api_key' );

		$result = $this->mailer->pre_wp_mail( null, $this->base_atts() );

		$this->assertNull( $result );
		$this->assertNull( $this->latest_log_row() );
	}

	public function test_configured_with_succeeding_fake_backend_returns_true_and_logs_sent() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-123' ) );
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertTrue( $result );

		$row = $this->latest_log_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'fake', $row['backend'] );
		$this->assertSame( 'msg-123', $row['message_id'] );
	}

	public function test_permanent_failure_is_marked_failed_immediately_and_fires_wp_mail_failed() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Euromail_Permanent_Exception( 'boom' ) ) );
			}
		);

		$captured_error = null;
		add_action(
			'wp_mail_failed',
			function ( $error ) use ( &$captured_error ) {
				$captured_error = $error;
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertFalse( $result );

		$row = $this->latest_log_row();
		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertStringContainsString( 'boom', $row['error'] );
		$this->assertNull( $row['next_attempt_at'] );

		$this->assertInstanceOf( WP_Error::class, $captured_error );
		$this->assertSame( 'euromail_send_failed', $captured_error->get_error_code() );
	}

	public function test_retryable_failure_is_put_in_retrying_status_with_backoff_and_still_fires_wp_mail_failed() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Euromail_Retryable_Exception( 'temporary boom' ) ) );
			}
		);

		$fired = false;
		add_action(
			'wp_mail_failed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		// The synchronous wp_mail() call still reports failure — we don't
		// know yet whether the background retry will succeed.
		$this->assertFalse( $result );
		$this->assertTrue( $fired, 'wp_mail_failed must still fire on the initial attempt, even though a retry is queued.' );

		$row = $this->latest_log_row();
		$this->assertSame( 'retrying', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNotNull( $row['next_attempt_at'] );

		$seconds_until_retry = strtotime( $row['next_attempt_at'] ) - time();
		$this->assertGreaterThan( 0, $seconds_until_retry );
		$this->assertLessThanOrEqual( Euromail_Queue::BACKOFF_SECONDS[0] + 5, $seconds_until_retry, 'First retry should be scheduled around the first backoff step.' );
	}

	public function test_backend_throwing_error_does_not_fatal() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Error( 'fatal-ish error' ) ) );
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertFalse( $result );

		$row = $this->latest_log_row();
		$this->assertNotNull( $row );
		// An unrecognized Throwable (including Error) defaults to
		// retryable, so this ends up 'retrying' rather than immediately
		// failed — the key guarantee is simply that it was caught and
		// logged, not left to fatal.
		$this->assertSame( 'retrying', $row['status'] );
		$this->assertStringContainsString( 'fatal-ish error', $row['error'] );
	}

	public function test_stored_payload_never_contains_attachment_content_regardless_of_status() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_store_body', true ); // even with store_body ON

		$path = $this->make_temp_file( 'attachment bytes' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Euromail_Permanent_Exception( 'boom' ) ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text', '', array( $path ) );

		$row = $this->latest_log_row();
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNotNull( $row['payload'], 'A failed row must keep its payload metadata so it can be resent from the admin log table.' );

		$payload = json_decode( $row['payload'], true );
		$this->assertArrayNotHasKey( 'content', $payload['attachments'][0], 'Attachment content must never be stored, at any status — a resend re-reads it from disk.' );
		$this->assertSame( $path, $payload['attachments'][0]['path'], 'The path must be retained so a retry/resend can re-read the file.' );
	}

	public function test_retrying_send_retains_payload_metadata_for_the_retry_queue() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Euromail_Retryable_Exception( 'temporary boom' ) ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$row = $this->latest_log_row();
		$this->assertSame( 'retrying', $row['status'] );
		$this->assertNotNull( $row['payload'] );
	}

	public function test_successful_send_with_store_body_disabled_leaves_payload_null() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_store_body', false );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$row = $this->latest_log_row();
		$this->assertSame( 'sent', $row['status'] );
		$this->assertNull( $row['payload'] );
	}

	public function test_stored_payload_never_contains_attachment_content_on_success() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_store_body', true );

		$path = $this->make_temp_file( 'attachment bytes' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text', '', array( $path ) );

		$row = $this->latest_log_row();
		$this->assertSame( 'sent', $row['status'] );
		$this->assertNotNull( $row['payload'] );

		$payload = json_decode( $row['payload'], true );
		$this->assertArrayHasKey( 'attachments', $payload );
		$this->assertCount( 1, $payload['attachments'] );
		$this->assertArrayNotHasKey( 'content', $payload['attachments'][0] );
		$this->assertArrayHasKey( 'path', $payload['attachments'][0] );
		$this->assertArrayHasKey( 'size', $payload['attachments'][0] );
	}

	public function test_send_succeeds_even_when_log_insert_fails() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-999' ) );
			}
		);

		global $wpdb;
		$table = Euromail_Logger::table_name();

		$break_insert = function ( $query ) use ( $table ) {
			if ( 0 === strpos( ltrim( $query ), 'INSERT INTO' ) && false !== strpos( $query, $table ) ) {
				// Deliberately invalid SQL against the same table, so
				// $wpdb->insert() fails without touching any other query.
				return 'SELECT 1 FROM `' . $table . '` WHERE 1 = 0 AND this_column_does_not_exist = 1';
			}
			return $query;
		};

		$before_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );

		$wpdb->suppress_errors( true );
		add_filter( 'query', $break_insert );

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		remove_filter( 'query', $break_insert );
		$wpdb->suppress_errors( false );

		$this->assertTrue( $result, 'A failed log insert must not stop the send from succeeding.' );

		$after_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		$this->assertSame( $before_count, $after_count, 'No row should have been inserted.' );
	}

	public function test_wp_mail_succeeded_fires_with_original_atts_shape_on_success() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		$captured = null;
		add_action(
			'wp_mail_succeeded',
			function ( $mail_data ) use ( &$captured ) {
				$captured = $mail_data;
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertIsArray( $captured );
		$this->assertSame( 'recipient@example.com', $captured['to'] );
		$this->assertSame( 'Hello', $captured['subject'] );
		$this->assertSame( 'Body text', $captured['message'] );
		$this->assertArrayHasKey( 'headers', $captured );
		$this->assertArrayHasKey( 'attachments', $captured );
	}

	public function test_wp_mail_succeeded_does_not_fire_on_failure() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Exception( 'boom' ) ) );
			}
		);

		$fired = false;
		add_action(
			'wp_mail_succeeded',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertFalse( $fired );
	}

	// -- Backend chain / fallback --

	public function test_backend_chain_order_reflects_backend_and_fallback_settings() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_backend', 'api' );
		update_option( 'euromail_fallback_enabled', true );
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$seen_keys = null;
		add_filter(
			'euromail_backends',
			function ( $backends ) use ( &$seen_keys ) {
				$seen_keys = array_keys( $backends );
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertSame( array( 'api', 'smtp' ), $seen_keys );
	}

	public function test_smtp_primary_puts_api_second_when_fallback_enabled() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_backend', 'smtp' );
		update_option( 'euromail_fallback_enabled', true );
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$seen_keys = null;
		add_filter(
			'euromail_backends',
			function ( $backends ) use ( &$seen_keys ) {
				$seen_keys = array_keys( $backends );
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertSame( array( 'smtp', 'api' ), $seen_keys );
	}

	public function test_fallback_disabled_only_includes_the_primary_backend() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_backend', 'api' );
		update_option( 'euromail_fallback_enabled', false );
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$seen_keys = null;
		add_filter(
			'euromail_backends',
			function ( $backends ) use ( &$seen_keys ) {
				$seen_keys = array_keys( $backends );
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-1' ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertSame( array( 'api' ), $seen_keys );
	}

	public function test_fallback_backend_is_tried_when_the_primary_fails_permanently() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array(
					'api'  => Euromail_Test_Fake_Backend::failing( new Euromail_Permanent_Exception( 'primary down' ) ),
					'smtp' => Euromail_Test_Fake_Backend::succeeding( 'msg-fallback' ),
				);
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertTrue( $result );

		$row = $this->latest_log_row();
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'smtp', $row['backend'] );
		$this->assertSame( 'msg-fallback', $row['message_id'] );
	}

	public function test_fallback_backend_is_tried_when_the_primary_fails_retryably() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array(
					'api'  => Euromail_Test_Fake_Backend::failing( new Euromail_Retryable_Exception( 'primary busy' ) ),
					'smtp' => Euromail_Test_Fake_Backend::succeeding( 'msg-fallback' ),
				);
			}
		);

		$result = wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$this->assertTrue( $result, 'Fallback must be tried on ANY primary failure, retryable or permanent, not only permanent ones.' );

		$row = $this->latest_log_row();
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'smtp', $row['backend'] );
	}
}
