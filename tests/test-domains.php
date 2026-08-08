<?php
/**
 * Tests for Euromail_Admin::render_domains_page().
 *
 * Guarantees under test:
 * - An unconfigured site shows a plain notice, not a fatal.
 * - Domains from the SDK are listed with their name and status.
 * - An AuthenticationException (403, insufficient scope) shows a
 *   graceful, specific notice, not a fatal.
 * - Any other SDK failure shows a graceful notice (the exception's own
 *   message), not a fatal.
 * - A non-array element in the SDK's response (the SDK returns domains as
 *   raw arrays with no typed model, so a malformed response element is not
 *   something the plugin controls) is skipped rather than reaching
 *   domain_field()/domain_status_label() and fataling the page.
 *
 * @package Euromail
 */

class Euromail_Test_Fake_Domains_Resource {

	private $domains;
	private $exception;

	public static function returning( array $domains ) {
		$resource          = new self();
		$resource->domains = $domains;
		return $resource;
	}

	public static function failing( Throwable $exception ) {
		$resource            = new self();
		$resource->exception = $exception;
		return $resource;
	}

	public function all() {
		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return $this->domains;
	}
}

class Euromail_Test_Fake_Domains_Client {

	public $domains;

	public function __construct( Euromail_Test_Fake_Domains_Resource $resource ) {
		$this->domains = $resource;
	}
}

class Test_Euromail_Admin_Domains_Page extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->admin = new Euromail_Admin();
	}

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		remove_all_filters( 'euromail_domains_client' );
		parent::tear_down();
	}

	private function render() {
		ob_start();
		$this->admin->render_domains_page();
		return ob_get_clean();
	}

	private function fake_client_filter( Euromail_Test_Fake_Domains_Resource $resource ) {
		return function () use ( $resource ) {
			return new Euromail_Test_Fake_Domains_Client( $resource );
		};
	}

	public function test_unconfigured_site_shows_a_notice_not_a_fatal() {
		$html = $this->render();

		$this->assertStringContainsString( 'Configure an API key', $html );
	}

	public function test_lists_domains_with_name_and_status() {
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter(
			'euromail_domains_client',
			$this->fake_client_filter(
				Euromail_Test_Fake_Domains_Resource::returning(
					array(
						array(
							'domain' => 'example.com',
							'status' => 'verified',
						),
						array(
							'domain' => 'unverified.example.com',
							'status' => 'pending',
						),
					)
				)
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'example.com', $html );
		$this->assertStringContainsString( 'verified', $html );
		$this->assertStringContainsString( 'unverified.example.com', $html );
		$this->assertStringContainsString( 'pending', $html );
	}

	public function test_authentication_exception_shows_a_graceful_notice_not_a_fatal() {
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter(
			'euromail_domains_client',
			$this->fake_client_filter(
				Euromail_Test_Fake_Domains_Resource::failing( new EuroMail\Exceptions\AuthenticationException( 'insufficient scope', 403 ) )
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'does not have permission', $html );
	}

	public function test_generic_sdk_failure_shows_a_graceful_notice_not_a_fatal() {
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter(
			'euromail_domains_client',
			$this->fake_client_filter( Euromail_Test_Fake_Domains_Resource::failing( new Exception( 'network unreachable' ) ) )
		);

		$html = $this->render();

		$this->assertStringContainsString( 'network unreachable', $html );
	}

	public function test_a_non_array_element_in_the_response_is_skipped_without_a_fatal() {
		update_option( 'euromail_api_key', 'em_live_test' );
		add_filter(
			'euromail_domains_client',
			$this->fake_client_filter(
				Euromail_Test_Fake_Domains_Resource::returning(
					array(
						array(
							'domain' => 'example.com',
							'status' => 'verified',
						),
						'this-is-not-an-array', // Malformed element, e.g. an unexpected SDK response shape.
					)
				)
			)
		);

		// No fatal is the primary assertion here: PHPUnit turns a PHP
		// fatal/TypeError into a test failure, so simply reaching this
		// line proves the malformed element didn't reach domain_field()
		// (which requires an array argument).
		$html = $this->render();

		$this->assertStringContainsString( 'example.com', $html, 'The well-formed row must still render.' );
		$this->assertStringNotContainsString( 'this-is-not-an-array', $html, 'The malformed element must be skipped, not rendered.' );
	}
}
