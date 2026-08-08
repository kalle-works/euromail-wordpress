<?php
/**
 * Tests for Euromail_Retention.
 *
 * Guarantees under test:
 * - Terminal-status rows older than euromail_log_retention_days are deleted.
 * - Non-terminal rows (e.g. 'sending') survive regardless of age.
 * - Fresh terminal rows (within the retention window) survive.
 * - A retention setting below 1 day is treated as 1 day, not "prune everything"
 *   or "prune nothing".
 * - The daily cron event is wired to Euromail_Retention::prune() so
 *   `wp cron event run euromail_prune_logs` actually does something.
 *
 * @package Euromail
 */

class Test_Euromail_Retention extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'euromail_log_retention_days' );
		parent::tear_down();
	}

	private function insert_row( $status, $days_old ) {
		$created_at = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );

		return Euromail_Logger::create(
			array(
				'status'          => $status,
				'created_at'      => $created_at,
				'updated_at'      => $created_at,
				'idempotency_key' => wp_generate_uuid4(),
				'mail_to'         => 'recipient@example.com',
				'subject'         => 'Retention test',
			)
		);
	}

	public function test_prunes_old_terminal_rows_but_keeps_fresh_and_nonterminal() {
		update_option( 'euromail_log_retention_days', 30 );

		$old_sent    = $this->insert_row( 'sent', 40 );
		$old_failed  = $this->insert_row( 'failed', 35 );
		$fresh_sent  = $this->insert_row( 'sent', 5 );
		$old_sending = $this->insert_row( 'sending', 40 );

		Euromail_Retention::prune();

		$this->assertNull( Euromail_Logger::get( $old_sent ), 'Old sent rows must be pruned.' );
		$this->assertNull( Euromail_Logger::get( $old_failed ), 'Old failed rows must be pruned.' );
		$this->assertNotNull( Euromail_Logger::get( $fresh_sent ), 'Fresh rows must survive.' );
		$this->assertNotNull( Euromail_Logger::get( $old_sending ), 'Non-terminal rows must survive regardless of age.' );
	}

	public function test_retention_below_one_day_is_treated_as_one_day() {
		update_option( 'euromail_log_retention_days', 0 );

		// 12 hours old is the discriminating case: with the 1-day floor
		// applied, the cutoff is "now minus 1 day" and this row survives
		// (it's newer than that). Without the floor (days treated as 0,
		// cutoff = "now"), this row is older than "now" and would
		// incorrectly be pruned.
		$half_day_old = $this->insert_row( 'sent', 0.5 );
		$two_days_old = $this->insert_row( 'sent', 2 );

		Euromail_Retention::prune();

		$this->assertNotNull( Euromail_Logger::get( $half_day_old ), 'A half-day-old row must survive once retention floors to 1 day.' );
		$this->assertNull( Euromail_Logger::get( $two_days_old ), 'A 2-day-old row must still be pruned under the 1-day floor.' );
	}

	public function test_cron_hook_is_wired_to_prune() {
		$this->assertNotFalse(
			has_action( 'euromail_prune_logs', array( 'Euromail_Retention', 'prune' ) ),
			'euromail_prune_logs must be hooked to Euromail_Retention::prune() so wp cron event run works.'
		);
	}
}
