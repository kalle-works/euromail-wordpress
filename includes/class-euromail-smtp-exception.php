<?php
/**
 * Exception thrown by the SMTP backend, carrying whether the failure is
 * worth retrying.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Euromail_Smtp_Exception extends Exception {

	/**
	 * @var bool
	 */
	private $retryable;

	/**
	 * @param string $message   Error message.
	 * @param bool   $retryable Whether the same send is worth retrying later.
	 */
	public function __construct( $message, $retryable = true ) {
		parent::__construct( $message );

		$this->retryable = (bool) $retryable;
	}

	/**
	 * @return bool
	 */
	public function is_retryable() {
		return $this->retryable;
	}
}
