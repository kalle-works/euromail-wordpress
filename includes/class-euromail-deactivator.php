<?php
/**
 * Runs on plugin deactivation.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears scheduled cron events.
 */
class Euromail_Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'euromail_prune_logs' );
		wp_clear_scheduled_hook( 'euromail_process_retry_queue' );
	}
}
