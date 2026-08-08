<?php
/**
 * Runs on plugin activation.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the delivery log table and records the schema version.
 */
class Euromail_Activator {

	/**
	 * Current database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Activation entry point.
	 */
	public static function activate() {
		self::create_log_table();
		update_option( 'euromail_db_version', self::DB_VERSION );
		self::schedule_retention_pruning();
		self::schedule_retry_queue();
	}

	/**
	 * Schedule the daily log-retention pruning cron event, if not already scheduled.
	 */
	private static function schedule_retention_pruning() {
		if ( ! wp_next_scheduled( 'euromail_prune_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'euromail_prune_logs' );
		}
	}

	/**
	 * Schedule the retry-queue cron event, if not already scheduled. The
	 * euromail_minutely schedule itself is registered unconditionally in
	 * the main plugin file, not here, so it's always available by the time
	 * this runs.
	 */
	private static function schedule_retry_queue() {
		if ( ! wp_next_scheduled( 'euromail_process_retry_queue' ) ) {
			wp_schedule_event( time(), 'euromail_minutely', 'euromail_process_retry_queue' );
		}
	}

	/**
	 * Create (or upgrade) the {$wpdb->prefix}euromail_log table via dbDelta().
	 */
	private static function create_log_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'euromail_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sending',
			backend VARCHAR(10) DEFAULT NULL,
			message_id VARCHAR(255) DEFAULT NULL,
			api_id VARCHAR(64) DEFAULT NULL,
			idempotency_key CHAR(36) NOT NULL,
			mail_from VARCHAR(255) NOT NULL DEFAULT '',
			mail_to TEXT NOT NULL,
			subject TEXT NOT NULL,
			payload LONGTEXT DEFAULT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME DEFAULT NULL,
			error TEXT DEFAULT NULL,
			events LONGTEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY message_id (message_id),
			KEY api_id (api_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY next_attempt_at (next_attempt_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
