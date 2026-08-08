<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Euromail
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _euromail_manually_load_plugin() {
	require dirname( __DIR__ ) . '/euromail.php';
}
tests_add_filter( 'muplugins_loaded', '_euromail_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// The plugin only loads its admin-only classes when is_admin() is true (a
// deliberate perf optimization for real requests), which the PHPUnit CLI
// context never is. Load them directly so admin tests can reach them.
if ( ! class_exists( 'Euromail_Admin' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-euromail-admin.php';
}
if ( ! class_exists( 'Euromail_Log_Table' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-euromail-log-table.php';
}
