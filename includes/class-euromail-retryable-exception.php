<?php
/**
 * Thrown by a backend for a failure worth retrying later (transient
 * network/connection issues, temporary 4xx-style rejections, etc.).
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Euromail_Retryable_Exception extends Exception {}
