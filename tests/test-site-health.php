<?php
/**
 * Tests for Euromail_Site_Health.
 *
 * Guarantees under test:
 * - Unconfigured site: 'recommended', not 'critical' — nothing is broken,
 *   Euromail just isn't in use yet.
 * - API backend selected but no key: 'critical'.
 * - API backend selected, key set, account->get() succeeds: 'good'.
 * - API backend selected, key set, account->get() throws: 'critical' with
 *   the exception's own message surfaced.
 * - SMTP backend selected but incomplete: 'critical'.
 * - SMTP backend selected and complete: 'good' (no live connection
 *   attempted — completeness alone is the bar for SMTP).
 *
 * @package Euromail
 */

/**
 * Minimal fake of the SDK's Client, only exposing what run_test() touches
 * (->account->get()).
 */
class Euromail_Test_Site_Health_Account_Resource {

	private $exception;

	public static function succeeding() {
		return new self();
	}

	public static function failing( Throwable $exception ) {
		$resource            = new self();
		$resource->exception = $exception;
		return $resource;
	}

	public function get() {
		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return array( 'email' => 'test@example.com' );
	}
}

class Euromail_Test_Site_Health_Fake_Client {

	public $account;

	public function __construct( $account_resource ) {
		$this->account = $account_resource;
	}
}

class Test_Euromail_Site_Health extends WP_UnitTestCase {

	private $site_health;

	public function set_up() {
		parent::set_up();
		$this->site_health = new Euromail_Site_Health();
	}

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		delete_option( 'euromail_backend' );
		delete_option( 'euromail_smtp_host' );
		delete_option( 'euromail_smtp_auth' );
		delete_option( 'euromail_smtp_username' );
		delete_option( 'euromail_smtp_password' );
		remove_all_filters( 'euromail_site_health_client' );
		parent::tear_down();
	}

	private function fake_client_filter( $account_resource ) {
		return function () use ( $account_resource ) {
			return new Euromail_Test_Site_Health_Fake_Client( $account_resource );
		};
	}

	public function test_unconfigured_site_is_recommended_not_critical() {
		$result = $this->site_health->run_test();

		$this->assertSame( 'recommended', $result['status'] );
	}

	public function test_api_backend_selected_with_no_key_is_critical() {
		update_option( 'euromail_backend', 'api' );
		// Give SMTP real credentials too, so is_configured() (which is
		// backend-agnostic — true if EITHER backend has credentials) is
		// true overall; otherwise this state is indistinguishable from a
		// totally untouched install and correctly reports 'recommended'
		// instead. The point under test is specifically that the
		// SELECTED backend is broken, even though something else works.
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$result = $this->site_health->run_test();

		$this->assertSame( 'critical', $result['status'] );
	}

	public function test_api_backend_with_working_key_is_good() {
		update_option( 'euromail_backend', 'api' );
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter( 'euromail_site_health_client', $this->fake_client_filter( Euromail_Test_Site_Health_Account_Resource::succeeding() ) );

		$result = $this->site_health->run_test();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_api_backend_with_failing_account_call_is_critical_with_the_exception_message() {
		update_option( 'euromail_backend', 'api' );
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter( 'euromail_site_health_client', $this->fake_client_filter( Euromail_Test_Site_Health_Account_Resource::failing( new Exception( 'invalid key' ) ) ) );

		$result = $this->site_health->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'invalid key', $result['description'] );
	}

	public function test_smtp_backend_incomplete_is_critical() {
		update_option( 'euromail_backend', 'smtp' );
		// Same reasoning as the API case above: give the API backend real
		// credentials so is_configured() is true overall, isolating the
		// assertion to "the SELECTED backend specifically is broken."
		update_option( 'euromail_api_key', 'em_live_test' );

		$result = $this->site_health->run_test();

		$this->assertSame( 'critical', $result['status'] );
	}

	public function test_smtp_backend_complete_is_good_without_a_live_connection_attempt() {
		update_option( 'euromail_backend', 'smtp' );
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$result = $this->site_health->run_test();

		$this->assertSame( 'good', $result['status'] );
	}
}
