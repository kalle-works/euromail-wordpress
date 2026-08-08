<?php
/**
 * Tests for Euromail_Mailer.
 *
 * Guarantees under test:
 * - An unconfigured site never has its mail broken: pre_wp_mail() returns
 *   null so core wp_mail() keeps sending.
 * - Once configured, a successful backend produces a "sent" log row with
 *   the backend name and message ID, and wp_mail() returns true.
 * - A failing backend produces a "failed" log row and fires wp_mail_failed
 *   with a WP_Error, and wp_mail() returns false.
 * - Any Throwable a backend raises (not just Exception) is caught: wp_mail()
 *   never fatals, and the failure is logged the same way.
 * - The stored payload never contains attachment content (base64), and is
 *   nulled on every terminal outcome (success and failure) when
 *   euromail_store_body is disabled.
 * - A failed log insert doesn't stop the send or fatal; later log updates
 *   are skipped gracefully instead of writing to a nonexistent row.
 * - wp_mail_succeeded fires (with the original wp_mail() args) on success
 *   only, never on failure.
 *
 * These tests never require the euromail/euromail-php SDK: backends are
 * always injected as plain fakes via the `euromail_backends` filter.
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
	 *                             Exception and Error both qualify.
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

	public function test_configured_with_failing_fake_backend_returns_false_logs_failed_and_fires_wp_mail_failed() {
		update_option( 'euromail_api_key', 'em_live_test' );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Exception( 'boom', 42 ) ) );
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
		$this->assertStringContainsString( 'fake: 42 boom', $row['error'] );

		$this->assertInstanceOf( WP_Error::class, $captured_error );
		$this->assertSame( 'euromail_send_failed', $captured_error->get_error_code() );
	}

	public function test_backend_throwing_error_does_not_fatal_and_logs_failed() {
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
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'fatal-ish error', $row['error'] );
	}

	public function test_failed_send_with_store_body_disabled_leaves_payload_null() {
		update_option( 'euromail_api_key', 'em_live_test' );
		update_option( 'euromail_store_body', false );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Exception( 'boom' ) ) );
			}
		);

		wp_mail( 'recipient@example.com', 'Hello', 'Body text' );

		$row = $this->latest_log_row();
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNull( $row['payload'] );
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

	public function test_stored_payload_never_contains_attachment_content() {
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
}
