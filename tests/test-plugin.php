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
 * - init() re-runs the table migration when the stored euromail_db_version
 *   is behind Euromail_Activator::DB_VERSION — a site updated in place via
 *   the WordPress plugin updater (no deactivate/reactivate cycle) would
 *   otherwise keep an old table forever, and because
 *   Euromail_Logger::create() fails gracefully, that breakage is
 *   completely silent: no log rows, no retries, no webhook matching.
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

	public function test_init_migrates_an_outdated_schema_at_runtime() {
		global $wpdb;
		$table = $wpdb->prefix . 'euromail_log';

		// Simulate a site that updated the plugin in place: the 1.0.0 table
		// without the api_id column, and the old schema version recorded.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN api_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'euromail_db_version', '1.0.0' );

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotContains( 'api_id', $columns, 'Precondition: the simulated old table must lack api_id.' );

		$plugin = new Euromail_Plugin();
		$plugin->init();

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertContains( 'api_id', $columns, 'A plugin updated in place (no reactivation) must gain new schema columns on the next request.' );
		$this->assertSame( Euromail_Activator::DB_VERSION, get_option( 'euromail_db_version' ) );
	}

	public function test_init_skips_the_migration_when_the_version_is_current() {
		global $wpdb;
		$table = $wpdb->prefix . 'euromail_log';

		// With a CURRENT version recorded, init() must short-circuit before
		// dbDelta — observable because a manually removed column stays
		// removed. (A version mismatch, by contrast, restores it — see the
		// migration test above.)
		update_option( 'euromail_db_version', Euromail_Activator::DB_VERSION );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN api_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$plugin = new Euromail_Plugin();
		$plugin->init();

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotContains( 'api_id', $columns, 'A current schema version must not re-run dbDelta on every request.' );

		// Restore the real schema for subsequent tests: DDL is not rolled
		// back by the test transaction.
		Euromail_Activator::create_log_table();
	}
}
