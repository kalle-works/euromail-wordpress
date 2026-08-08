<?php
/**
 * Tests for Euromail_Plugin.
 *
 * Guarantees under test:
 * - init() schedules both cron events when the plugin is configured and
 *   they are found missing — a defense against a site's cron table being
 *   reset out-of-band (a staging clone, a migration, a cron-management
 *   plugin) without a deactivate/reactivate cycle.
 * - init() schedules nothing on an unconfigured site.
 * - init() never reschedules an event that is already scheduled.
 *
 * @package Euromail
 */

class Test_Euromail_Plugin extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		wp_clear_scheduled_hook( 'euromail_process_retry_queue' );
		wp_clear_scheduled_hook( 'euromail_prune_logs' );
		parent::tear_down();
	}

	public function test_init_schedules_both_events_when_configured_and_missing() {
		update_option( 'euromail_api_key', 'em_live_test' );

		$this->assertFalse( wp_next_scheduled( 'euromail_process_retry_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'euromail_prune_logs' ) );

		$plugin = new Euromail_Plugin();
		$plugin->init();

		$this->assertNotFalse( wp_next_scheduled( 'euromail_process_retry_queue' ) );
		$this->assertNotFalse( wp_next_scheduled( 'euromail_prune_logs' ) );
	}

	public function test_init_does_not_schedule_anything_on_an_unconfigured_site() {
		delete_option( 'euromail_api_key' );

		$plugin = new Euromail_Plugin();
		$plugin->init();

		$this->assertFalse( wp_next_scheduled( 'euromail_process_retry_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'euromail_prune_logs' ) );
	}

	public function test_init_does_not_reschedule_an_event_that_is_already_scheduled() {
		update_option( 'euromail_api_key', 'em_live_test' );

		$existing_timestamp = time() + 3600;
		wp_schedule_event( $existing_timestamp, 'euromail_minutely', 'euromail_process_retry_queue' );

		$plugin = new Euromail_Plugin();
		$plugin->init();

		$this->assertSame(
			$existing_timestamp,
			wp_next_scheduled( 'euromail_process_retry_queue' ),
			'An already-scheduled event must not be rescheduled to a new time.'
		);
	}
}
