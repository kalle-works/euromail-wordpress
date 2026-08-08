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
