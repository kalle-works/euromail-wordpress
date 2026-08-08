<?php
/**
 * Prunes old delivery log rows according to euromail_log_retention_days.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooked onto the daily `euromail_prune_logs` cron event.
 */
class Euromail_Retention {

	/**
	 * Statuses that are safe to prune once they're past retention. Rows in
	 * a non-terminal status (e.g. 'sending') are never deleted regardless
	 * of age, since M3's retry queue still needs them.
	 *
	 * @var array
	 */
	const TERMINAL_STATUSES = array( 'sent', 'failed', 'delivered', 'bounced', 'complained' );

	/**
	 * Delete terminal-status log rows older than the configured retention
	 * window. Hooked onto `euromail_prune_logs`.
	 *
	 * @return int|false Number of rows deleted, or false on a DB error.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) Euromail_Settings::get( 'euromail_log_retention_days' );

		if ( $days < 1 ) {
			$days = 1;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$status_placeholders = implode( ', ', array_fill( 0, count( self::TERMINAL_STATUSES ), '%s' ) );
		$table               = Euromail_Logger::table_name();

		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE status IN ({$status_placeholders}) AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			array_merge( self::TERMINAL_STATUSES, array( $cutoff ) )
		);

		return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
