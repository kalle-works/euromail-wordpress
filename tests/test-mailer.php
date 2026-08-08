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

	public static function failing( Exception $exception ) {
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

	public function set_up() {
		parent::set_up();
		$this->mailer = new Euromail_Mailer();
	}

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		remove_all_filters( 'euromail_backends' );
		remove_all_actions( 'wp_mail_failed' );
		parent::tear_down();
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
}
