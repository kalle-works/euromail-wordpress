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
		// runner — including `wp cron event run euromail_prune_logs` — can
		// always find it.
		add_action( 'euromail_prune_logs', array( 'Euromail_Retention', 'prune' ) );

		if ( is_admin() && class_exists( 'Euromail_Admin' ) ) {
			$admin = new Euromail_Admin();
			$admin->init();
		}
	}
}
