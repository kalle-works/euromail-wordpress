<?php
/**
 * Tests for Euromail_Queue.
 *
 * Guarantees under test:
 * - A queued row that now succeeds is marked sent, and wp_mail_succeeded
 *   fires with the stored to/subject.
 * - A queued row that fails again, retryably, with attempts still under
 *   the cap stays queued with an advanced next_attempt_at (following the
 *   fixed backoff table) and an incremented attempts count.
 * - A queued row that fails again with attempts about to reach the cap is
 *   marked permanently failed instead of queued again.
 * - A permanent (non-retryable) failure is marked failed immediately,
 *   regardless of how many attempts remain.
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

	public function tear_down() {
		remove_all_filters( 'euromail_backends' );
		remove_all_actions( 'wp_mail_succeeded' );
		parent::tear_down();
	}

	/**
	 * @param array $overrides Column overrides.
	 * @return int Inserted row ID.
	 */
	private function insert_queued_row( array $overrides = array() ) {
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
					'attachments' => array(),
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

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Exception( 'still down' ) ) ) );

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 2, (int) $row['attempts'] );
		$this->assertNotNull( $row['next_attempt_at'] );

		// attempts is now 2, so the backoff index is BACKOFF_SECONDS[1] (15 minutes).
		$expected_delay       = Euromail_Queue::BACKOFF_SECONDS[1];
		$seconds_until_retry  = strtotime( $row['next_attempt_at'] ) - time();
		$this->assertGreaterThan( $expected_delay - 10, $seconds_until_retry );
		$this->assertLessThanOrEqual( $expected_delay + 10, $seconds_until_retry );
	}

	public function test_process_row_marks_failed_once_attempts_are_exhausted() {
		$id = $this->insert_queued_row( array( 'attempts' => Euromail_Queue::MAX_ATTEMPTS - 1 ) );

		add_filter( 'euromail_backends', $this->fake_backends_filter( Euromail_Test_Fake_Backend::failing( new Exception( 'still down' ) ) ) );

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
