<?php
/**
 * Tests for Euromail_Smtp_Exception and Euromail_Mailer::is_retryable_exception().
 *
 * Guarantees under test:
 * - Euromail_Smtp_Exception reports exactly the retryable flag it was
 *   constructed with (both true and false), not a hardcoded value.
 * - Euromail_Mailer::is_retryable_exception() respects that flag for both
 *   directions, and defaults an unrecognized Throwable to retryable.
 *
 * @package Euromail
 */

class Test_Euromail_Smtp_Exception extends WP_UnitTestCase {

	public function test_reports_retryable_true_when_constructed_true() {
		$exception = new Euromail_Smtp_Exception( 'temporary', true );

		$this->assertTrue( $exception->is_retryable() );
	}

	public function test_reports_retryable_false_when_constructed_false() {
		$exception = new Euromail_Smtp_Exception( 'permanent', false );

		$this->assertFalse( $exception->is_retryable() );
	}

	public function test_defaults_to_retryable_when_not_specified() {
		$exception = new Euromail_Smtp_Exception( 'unspecified' );

		$this->assertTrue( $exception->is_retryable() );
	}

	public function test_mailer_classifies_a_retryable_smtp_exception_as_retryable() {
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Euromail_Smtp_Exception( 'temporary', true ) ) );
	}

	public function test_mailer_classifies_a_permanent_smtp_exception_as_not_retryable() {
		$this->assertFalse( Euromail_Mailer::is_retryable_exception( new Euromail_Smtp_Exception( 'permanent', false ) ) );
	}

	public function test_mailer_defaults_an_unrecognized_throwable_to_retryable() {
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Exception( 'unknown' ) ) );
		$this->assertTrue( Euromail_Mailer::is_retryable_exception( new Error( 'unknown' ) ) );
	}
}
