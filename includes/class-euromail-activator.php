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
	const DB_VERSION = '1.0.0';

	/**
	 * Activation entry point.
	 */
	public static function activate() {
		self::create_log_table();
		update_option( 'euromail_db_version', self::DB_VERSION );
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
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY next_attempt_at (next_attempt_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
