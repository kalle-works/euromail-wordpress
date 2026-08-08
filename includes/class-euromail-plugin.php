<?php
/**
 * Core plugin wiring.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires settings, the mailer, the admin UI, and the logger together.
 */
class Euromail_Plugin {

	/**
	 * Load the text domain and hook the mailer (and admin UI) into WordPress.
	 */
	public function init() {
		load_plugin_textdomain( 'euromail', false, dirname( plugin_basename( EUROMAIL_PLUGIN_FILE ) ) . '/languages' );

		$mailer = new Euromail_Mailer();
		add_filter( 'pre_wp_mail', array( $mailer, 'pre_wp_mail' ), 10, 2 );

		// Registered unconditionally (not only when is_admin()) so the cron
		// runner — including `wp cron event run euromail_prune_logs` /
		// `euromail_process_retry_queue` — can always find them.
		add_action( 'euromail_prune_logs', array( 'Euromail_Retention', 'prune' ) );
		add_action( 'euromail_process_retry_queue', array( 'Euromail_Queue', 'process' ) );

		$this->maybe_schedule_cron_events();

		if ( is_admin() && class_exists( 'Euromail_Admin' ) ) {
			$admin = new Euromail_Admin();
			$admin->init();
		}
	}

	/**
	 * Re-schedule both cron events on a normal request if either is found
	 * missing. Euromail_Activator only schedules them once, at activation
	 * time; a site whose cron table gets reset out-of-band — a staging
	 * clone, a migration, a cron-management plugin clearing events —
	 * without a deactivate/reactivate cycle would otherwise be left with
	 * retries and log pruning silently never running again.
	 * wp_next_scheduled() is a single indexed option lookup, cheap enough
	 * to check unconditionally, and the guard is idempotent by
	 * construction.
	 */
	private function maybe_schedule_cron_events() {
		if ( ! Euromail_Settings::is_configured() ) {
			return;
		}

		if ( ! wp_next_scheduled( 'euromail_process_retry_queue' ) ) {
			wp_schedule_event( time(), 'euromail_minutely', 'euromail_process_retry_queue' );
		}

		if ( ! wp_next_scheduled( 'euromail_prune_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'euromail_prune_logs' );
		}
	}
}
