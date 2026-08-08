<?php
/**
 * Thrown by a backend for a failure that retrying will not fix (bad
 * credentials, an invalid or rejected address, a missing attachment, etc.).
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Euromail_Permanent_Exception extends Exception {}
