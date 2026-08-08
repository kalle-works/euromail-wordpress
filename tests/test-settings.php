<?php
/**
 * Tests for Euromail_Settings.
 *
 * Guarantees under test:
 * - Every documented option has the documented default value.
 * - is_configured() is true when EITHER backend is configured: an API key,
 *   or SMTP with a host (and, when auth is on, a username and password).
 * - A wp-config.php constant, when defined, always wins over the stored option.
 *
 * @package Euromail
 */

class Test_Euromail_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		delete_option( 'euromail_smtp_host' );
		delete_option( 'euromail_smtp_auth' );
		delete_option( 'euromail_smtp_username' );
		delete_option( 'euromail_smtp_password' );
		parent::tear_down();
	}

	public function test_documented_defaults_are_returned_when_no_option_is_set() {
		$this->assertSame( 'api', Euromail_Settings::get( 'euromail_backend' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_api_key' ) );
		$this->assertSame( 'https://api.euromail.dev', Euromail_Settings::get( 'euromail_api_base_url' ) );
		$this->assertFalse( Euromail_Settings::get( 'euromail_force_from_enabled' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_force_from_email' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_force_from_name' ) );
		$this->assertTrue( Euromail_Settings::get( 'euromail_transactional_default' ) );
		$this->assertFalse( Euromail_Settings::get( 'euromail_tracking_default' ) );
		$this->assertFalse( Euromail_Settings::get( 'euromail_fallback_enabled' ) );
		$this->assertSame( 30, Euromail_Settings::get( 'euromail_log_retention_days' ) );
		$this->assertFalse( Euromail_Settings::get( 'euromail_store_body' ) );
		$this->assertFalse( Euromail_Settings::get( 'euromail_delete_data_on_uninstall' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_smtp_host' ) );
		$this->assertSame( 587, Euromail_Settings::get( 'euromail_smtp_port' ) );
		$this->assertSame( 'tls', Euromail_Settings::get( 'euromail_smtp_encryption' ) );
		$this->assertTrue( Euromail_Settings::get( 'euromail_smtp_auth' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_smtp_username' ) );
		$this->assertSame( '', Euromail_Settings::get( 'euromail_smtp_password' ) );
	}

	public function test_stored_option_is_used_when_no_constant_is_defined() {
		update_option( 'euromail_api_key', 'em_live_from_option' );

		$this->assertSame( 'em_live_from_option', Euromail_Settings::get( 'euromail_api_key' ) );
	}

	public function test_is_configured_is_false_with_neither_backend_set_up() {
		delete_option( 'euromail_api_key' );
		delete_option( 'euromail_smtp_host' );

		$this->assertFalse( Euromail_Settings::is_configured() );
		$this->assertFalse( Euromail_Settings::is_api_configured() );
		$this->assertFalse( Euromail_Settings::is_smtp_configured() );
	}

	public function test_is_configured_is_true_once_an_api_key_is_set() {
		update_option( 'euromail_api_key', 'em_live_test' );

		$this->assertTrue( Euromail_Settings::is_configured() );
		$this->assertTrue( Euromail_Settings::is_api_configured() );
	}

	public function test_smtp_with_auth_needs_host_username_and_password() {
		update_option( 'euromail_smtp_auth', true );

		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		$this->assertFalse( Euromail_Settings::is_smtp_configured(), 'Host alone is not enough when auth is required.' );

		update_option( 'euromail_smtp_username', 'user' );
		$this->assertFalse( Euromail_Settings::is_smtp_configured(), 'Username without a password is still not enough.' );

		update_option( 'euromail_smtp_password', 'pass' );
		$this->assertTrue( Euromail_Settings::is_smtp_configured() );
		$this->assertTrue( Euromail_Settings::is_configured() );
	}

	public function test_smtp_without_auth_only_needs_a_host() {
		update_option( 'euromail_smtp_auth', '0' );
		update_option( 'euromail_smtp_host', 'smtp.example.com' );

		$this->assertTrue( Euromail_Settings::is_smtp_configured() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_smtp_password_constant_overrides_option() {
		define( 'EUROMAIL_SMTP_PASSWORD', 'from_constant' );

		update_option( 'euromail_smtp_password', 'from_option' );

		$this->assertSame( 'from_constant', Euromail_Settings::get( 'euromail_smtp_password' ) );
	}

	/**
	 * A wp-config.php constant must win over the option even when both are
	 * set, so hosts can lock secrets outside the database. Constants can't
	 * be undefined once set, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_option_value() {
		define( 'EUROMAIL_API_KEY', 'em_live_from_constant' );

		update_option( 'euromail_api_key', 'em_live_from_option' );

		$this->assertSame( 'em_live_from_constant', Euromail_Settings::get( 'euromail_api_key' ) );
	}
}
