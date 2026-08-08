<?php
/**
 * Uninstall routine.
 *
 * Only removes data when the site owner opted in via the "Delete data on
 * uninstall" setting.
 *
 * @package Euromail
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'euromail_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'euromail_log' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$options = array(
	'euromail_db_version',
	'euromail_backend',
	'euromail_api_key',
	'euromail_api_base_url',
	'euromail_force_from_enabled',
	'euromail_force_from_email',
	'euromail_force_from_name',
	'euromail_transactional_default',
	'euromail_tracking_default',
	'euromail_fallback_enabled',
	'euromail_log_retention_days',
	'euromail_store_body',
	'euromail_delete_data_on_uninstall',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
