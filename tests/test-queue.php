<?php
/**
 * Tests for Euromail_Queue.
 *
 * Guarantees under test:
 * - A queued row that now succeeds is marked sent, and wp_mail_succeeded
 *   fires with the stored to/subject.
 * - A queued row that fails again, retryably, with attempts still under
 *   the cap stays in 'queued' with an advanced next_attempt_at (the fixed
 *   backoff table) and an incremented attempts count.
 * - next_attempt_at honors the backend's own Retry-After hint directly when
 *   it gives one (even when that's shorter than the fixed backoff step),
 *   falling back to the fixed backoff table otherwise.
 * - A queued row that fails again with attempts about to reach the cap is
 *   marked permanently failed instead of retried again.
 * - A permanent (non-retryable) failure is marked failed immediately,
 *   regardless of how many attempts remain.
 * - euromail_store_body is honored on a 'failed' transition reached via the
 *   retry queue exactly as it is on the initial attempt: off nulls the
 *   payload, on keeps it with attachment content stripped.
 * - The claim guard is atomic: a row not in 'queued' status is left
 *   completely untouched by process_row() (no reprocessing of an
 *   already-claimed or already-finished row).
 * - process() only picks up rows whose next_attempt_at is due, and leaves
 *   rows scheduled in the future alone.
 * - A queued row with a missing/unreadable payload is marked failed with
 *   an explanatory error, rather than fataling.
 *
 * @package Euromail
 */

class Test_Euromail_Queue extends WP_UnitTestCase {

	/**
	 * Temp files created by a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	public function tear_down() {
		remove_all_filters( 'euromail_backends' );
		remove_all_actions( 'wp_mail_succeeded' );

		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	private function make_temp_file( $contents ) {
		$path = tempnam( sys_get_temp_dir(), 'euromail-queue-test-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * @param array $overrides           Column overrides.
	 * @param array $attachment_overrides Canonical attachment entries for the stored payload.
	 * @return int Inserted row ID.
	 */
	private function insert_queued_row( array $overrides = array(), array $attachment_overrides = array() ) {
		$defaults = array(
			'status'          => 'queued',
			'idempotency_key' => wp_generate_uuid4(),
			'mail_from'       => 'wordpress@example.com',
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Queued test',
			'attempts'        => 1,
			'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), // already due
			'payload'         => wp_json_encode(
				array(
					'from'        => 'wordpress@example.com',
					'from_name'   => 'WordPress',
					'to'          => array( 'recipient@example.com' ),
					'cc'          => array(),
					'bcc'         => array(),
					'reply_to'    => '',
					'subject'     => 'Queued test',
					'text_body'   => 'Body text',
					'headers'     => array(),
					'attachments' => $attachment_overrides,
				)
			),
		);

		return Euromail_Logger::create( array_merge( $defaults, $overrides ) );
	}

	private function fake_backends_filter( $backend ) {
		return function () use ( $backend ) {
			return array( 'fake' => $backend );
		};
	}

	public function test_process_row_marks_sent_on_success() {
		$id = $this->insert_queued_row();

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::succeeding( 'msg-retry-1' ) ) );

		$captured = null;
		add_action(
			'wp_mail_succeeded',
			function ( $data ) use ( &$captured ) {
				$captured = $data;
			}
		);

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'fake', $row['backend'] );
		$this->assertSame( 'msg-retry-1', $row['message_id'] );
		$this->assertSame( 2, (int) $row['attempts'] );
		$this->assertNull( $row['next_attempt_at'] );

		$this->assertIsArray( $captured );
		$this->assertSame( 'recipient@example.com', $captured['to'] );
	}

	public function test_process_row_reschedules_a_retryable_failure_under_the_attempt_cap() {
		$id = $this->insert_queued_row( array( 'attempts' => 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Euromail_Smtp_Exception( 'still down', true ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 2, (int) $row['attempts'] );
		$this->assertNotNull( $row['next_attempt_at'] );

		// attempts is now 2, so the backoff index is BACKOFF_SECONDS[1] (15 minutes).
		$expected_delay      = Euromail_Queue::BACKOFF_SECONDS[1];
		$seconds_until_retry = strtotime( $row['next_attempt_at'] ) - time();
		$this->assertGreaterThan( $expected_delay - 10, $seconds_until_retry );
		$this->assertLessThanOrEqual( $expected_delay + 10, $seconds_until_retry );
	}

	public function test_next_attempt_at_honors_a_shorter_retry_after_directly() {
		// At attempts=2 the fixed backoff step would be 900s (15 minutes),
		// but a backend-provided Retry-After must win outright, even a
		// much shorter one — the server's own hint is authoritative, not a
		// floor under it.
		$id = $this->insert_queued_row( array( 'attempts' => 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( $this->rate_limited_exception( 10 ) ) ) );

		Euromail_Queue::process_row( $id );

		$row                 = Euromail_Logger::get( $id );
		$seconds_until_retry = strtotime( $row['next_attempt_at'] ) - time();
		$this->assertLessThan( Euromail_Queue::BACKOFF_SECONDS[1], $seconds_until_retry, 'A short Retry-After must be honored directly, not floored at the backoff step.' );
		$this->assertGreaterThan( 0, $seconds_until_retry );
	}

	public function test_next_attempt_at_honors_a_longer_retry_after_directly() {
		$id = $this->insert_queued_row( array( 'attempts' => 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( $this->rate_limited_exception( 21600 ) ) ) );

		Euromail_Queue::process_row( $id );

		$row                 = Euromail_Logger::get( $id );
		$seconds_until_retry = strtotime( $row['next_attempt_at'] ) - time();
		$this->assertGreaterThan( 21600 - 10, $seconds_until_retry, 'A long Retry-After must be honored, not capped at the shorter backoff step.' );
	}

	/**
	 * Build a Throwable that Euromail_Mailer recognizes as retryable with a
	 * specific Retry-After hint. EuroMailException::getRetryAfter() is the
	 * only source of that hint in the real code, so use the SDK's
	 * RateLimitException directly rather than reinventing the mechanism in
	 * a test-only class.
	 *
	 * @param int $retry_after Seconds.
	 * @return Throwable
	 */
	private function rate_limited_exception( $retry_after ) {
		if ( ! EUROMAIL_SDK_LOADED ) {
			$this->markTestSkipped( 'euromail/euromail-php SDK not installed.' );
		}

		return new EuroMail\Exceptions\RateLimitException( 'rate limited', $retry_after, 429 );
	}

	public function test_process_row_marks_failed_once_attempts_are_exhausted() {
		$id = $this->insert_queued_row( array( 'attempts' => Euromail_Queue::MAX_ATTEMPTS - 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Euromail_Smtp_Exception( 'still down', true ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( Euromail_Queue::MAX_ATTEMPTS, (int) $row['attempts'] );
		$this->assertNull( $row['next_attempt_at'] );
	}

	public function test_process_row_marks_a_permanent_failure_failed_immediately() {
		$id = $this->insert_queued_row( array( 'attempts' => 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Euromail_Smtp_Exception( 'bad credentials', false ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 2, (int) $row['attempts'] );
	}

	public function test_process_row_failed_with_store_body_disabled_nulls_the_payload() {
		update_option( 'euromail_store_body', false );

		$id = $this->insert_queued_row( array( 'attempts' => 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Euromail_Smtp_Exception( 'bad credentials', false ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNull( $row['payload'], 'A permanent failure reached via the retry queue must respect euromail_store_body exactly like the initial attempt does.' );

		delete_option( 'euromail_store_body' );
	}

	public function test_process_row_failed_with_store_body_enabled_strips_attachment_content() {
		update_option( 'euromail_store_body', true );

		$path = $this->make_temp_file( 'attachment bytes' );

		$id = $this->insert_queued_row(
			array( 'attempts' => 1 ),
			array(
				array(
					'filename'     => 'file.txt',
					'content_type' => 'text/plain',
					'path'         => $path,
					'content'      => base64_encode( 'attachment bytes' ),
					'size'         => 17,
				),
			)
		);

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Euromail_Smtp_Exception( 'bad credentials', false ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNotNull( $row['payload'] );

		$payload = json_decode( $row['payload'], true );
		$this->assertArrayNotHasKey( 'content', $payload['attachments'][0], 'Attachment content must be stripped even though store_body is on.' );
		$this->assertSame( $path, $payload['attachments'][0]['path'], 'The path is kept so a resend can re-read the file.' );

		delete_option( 'euromail_store_body' );
	}

	public function test_process_row_is_a_noop_for_a_row_not_in_queued_status() {
		// Deliberately 'failed', not 'sending': the claim UPDATE sets
		// status to 'sending', so starting from 'sending' would make a
		// no-op guard indistinguishable from a real claim — MySQL reports
		// 0 affected rows either way when the value doesn't actually
		// change. Starting from 'failed' means a missing status guard
		// would visibly flip the row to 'sending' and this test would
		// catch it.
		$id = $this->insert_queued_row( array( 'status' => 'failed', 'attempts' => 3 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::succeeding( 'msg-should-not-happen' ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'], 'A row not in queued status must be left completely untouched.' );
		$this->assertSame( 3, (int) $row['attempts'] );
	}

	public function test_claim_for_retry_only_lets_one_caller_win() {
		$id = $this->insert_queued_row();

		$first_claim  = Euromail_Logger::claim_for_retry( $id );
		$second_claim = Euromail_Logger::claim_for_retry( $id );

		$this->assertTrue( $first_claim, 'The first caller must win the claim.' );
		$this->assertFalse( $second_claim, 'A second caller must not also claim an already-claimed row.' );
	}

	public function test_process_only_picks_up_rows_that_are_due() {
		$due_id     = $this->insert_queued_row( array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		$not_due_id = $this->insert_queued_row( array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::succeeding( 'msg-batch' ) ) );

		Euromail_Queue::process();

		$due_row     = Euromail_Logger::get( $due_id );
		$not_due_row = Euromail_Logger::get( $not_due_id );

		$this->assertSame( 'sent', $due_row['status'] );
		$this->assertSame( 'queued', $not_due_row['status'], 'A row scheduled in the future must not be processed yet.' );
	}

	public function test_process_row_fails_gracefully_when_payload_is_missing() {
		$id = $this->insert_queued_row( array( 'payload' => null ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNotEmpty( $row['error'] );
	}
}
