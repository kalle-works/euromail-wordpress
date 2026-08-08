<?php
/**
 * Tests for Euromail_Settings.
 *
 * Guarantees under test:
 * - Every documented option has the documented default value.
 * - is_configured() reflects only the presence of an API key.
 * - A wp-config.php constant, when defined, always wins over the stored option.
 *
 * @package Euromail
 */

class Test_Euromail_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'euromail_api_key' );
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
	}

	public function test_stored_option_is_used_when_no_constant_is_defined() {
		update_option( 'euromail_api_key', 'em_live_from_option' );

		$this->assertSame( 'em_live_from_option', Euromail_Settings::get( 'euromail_api_key' ) );
	}

	public function test_is_configured_is_false_without_an_api_key() {
		delete_option( 'euromail_api_key' );

		$this->assertFalse( Euromail_Settings::is_configured() );
	}

	public function test_is_configured_is_true_once_an_api_key_is_set() {
		update_option( 'euromail_api_key', 'em_live_test' );

		$this->assertTrue( Euromail_Settings::is_configured() );
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
