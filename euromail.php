<?php
/**
 * Plugin Name:       Euromail – SMTP & Email API
 * Plugin URI:        https://euromail.dev
 * Description:       Routes wp_mail() through the euromail.dev transactional email API, with an SMTP fallback and a delivery log.
 * Version:           1.0.0
 * Author:            Kalle
 * Author URI:        https://kalle.works
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       euromail
 * Domain Path:       /languages
 * Requires at least: 5.7
 * Requires PHP:      7.4
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EUROMAIL_VERSION', '1.0.0' );
define( 'EUROMAIL_PLUGIN_FILE', __FILE__ );
define( 'EUROMAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EUROMAIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( EUROMAIL_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once EUROMAIL_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Whether the euromail/euromail-php SDK classes are available.
 *
 * The plugin must keep working (falling back to core wp_mail()) even when
 * the SDK has not been installed via Composer yet.
 */
define( 'EUROMAIL_SDK_LOADED', interface_exists( 'EuroMail\\Http\\TransportInterface' ) && class_exists( 'EuroMail\\Client' ) );

require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-settings.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-status.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-logger.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-retention.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-smtp-exception.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-smtp-backend.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-email-normalizer.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-mailer.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-queue.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-webhook-controller.php';

if ( EUROMAIL_SDK_LOADED ) {
	require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-wp-transport.php';
	require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-api-backend.php';
}

if ( is_admin() ) {
	require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-admin.php';
	require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-log-table.php';
	require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-site-health.php';
}

require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-activator.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-deactivator.php';
require_once EUROMAIL_PLUGIN_DIR . 'includes/class-euromail-plugin.php';

register_activation_hook( EUROMAIL_PLUGIN_FILE, array( 'Euromail_Activator', 'activate' ) );
register_deactivation_hook( EUROMAIL_PLUGIN_FILE, array( 'Euromail_Deactivator', 'deactivate' ) );

/**
 * Register the euromail_minutely cron schedule. Registered unconditionally
 * at the top level (not inside Euromail_Plugin::init()) so it is always in
 * place before wp_schedule_event() is called — including during
 * activation, where the exact ordering relative to plugins_loaded isn't
 * guaranteed.
 */
function euromail_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['euromail_minutely'] ) ) {
		$schedules['euromail_minutely'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Euromail retry queue)', 'euromail' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'euromail_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

/**
 * Boot the plugin once all other plugins have loaded.
 */
function euromail_init_plugin() {
	$plugin = new Euromail_Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'euromail_init_plugin' );
