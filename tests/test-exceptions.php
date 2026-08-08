<?php
/**
 * Tests for Euromail_Retryable_Exception, Euromail_Permanent_Exception, and
 * Euromail_Mailer::is_retryable_exception().
 *
 * Guarantees under test:
 * - Euromail_Mailer::is_retryable_exception() classifies
 *   Euromail_Retryable_Exception as retryable and
 *   Euromail_Permanent_Exception as not, regardless of message content —
 *   the classification is by exception type.
 * - An unrecognized Throwable (plain Exception, Error, ...) defaults to
 *   retryable.
 *
 * @package Euromail
 */

class Test_Euromail_Exception_Classification extends WP_UnitTestCase {

	public function test_retryable_exception_is_classified_retryable() {
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Euromail_Retryable_Exception( 'temporary' ) ) );
	}

	public function test_permanent_exception_is_classified_not_retryable() {
		$this->assertFalse( Euromail_Mailer::is_retryable_exception( new Euromail_Permanent_Exception( 'permanent' ) ) );
	}

	public function test_unrecognized_throwable_defaults_to_retryable() {
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Exception( 'unknown' ) ) );
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Error( 'unknown' ) ) );
	}
}
