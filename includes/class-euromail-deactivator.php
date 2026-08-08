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
 * Clears scheduled cron events. The retry queue itself ships in a later
 * milestone, but the hook name is reserved here so activation/deactivation
 * stay symmetric once it exists.
 */
class Euromail_Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'euromail_retry_failed_emails' );
	}
}
