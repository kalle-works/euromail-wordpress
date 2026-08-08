<?php
/**
 * Tests for Euromail_Admin.
 *
 * Guarantees under test:
 * - The Settings page refuses to enable Force From when the submitted
 *   email address is empty or invalid, and records an error notice instead
 *   of silently saving a broken pair.
 * - The "Verify key" AJAX handler checks whichever key the browser posted
 *   (falling back to the saved key only when the field was empty), and
 *   never writes the posted key to the option — verification is read-only.
 *
 * @package Euromail
 */

/**
 * Minimal fake of the SDK's Client, only exposing what ajax_verify_key()
 * touches (->account->get()).
 */
class Euromail_Test_Fake_Account_Resource {

	private $result;

	public function __construct( $result ) {
		$this->result = $result;
	}

	public function get() {
		return $this->result;
	}
}

class Euromail_Test_Fake_Sdk_Client {

	public $account;

	public function __construct( $result ) {
		$this->account = new Euromail_Test_Fake_Account_Resource( $result );
	}
}

/**
 * Fake backend that records the canonical email it was asked to send, so a
 * test can inspect what a resend actually rehydrated before sending.
 */
class Euromail_Test_Capturing_Backend {

	public $captured_email;

	private $result;

	public function __construct( $message_id ) {
		$this->result = array( 'message_id' => $message_id );
	}

	public function send( array $email, $idempotency_key ) {
		$this->captured_email = $email;
		return $this->result;
	}
}

class Test_Euromail_Admin_Settings_Save extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = new Euromail_Admin();
	}

	public function tear_down() {
		delete_option( 'euromail_force_from_enabled' );
		delete_option( 'euromail_force_from_email' );
		delete_option( 'euromail_force_from_name' );
		$_POST = array();
		parent::tear_down();
	}

	private function submit_settings( array $post ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST                             = $post;
		$_POST['euromail_settings_submit'] = '1';
		$_POST['euromail_settings_nonce']  = wp_create_nonce( 'euromail_settings' );

		ob_start();
		$this->admin->render_settings_page();
		ob_end_clean();
	}

	public function test_force_from_with_invalid_email_is_rejected_without_enabling() {
		$this->submit_settings(
			array(
				'euromail_force_from_enabled' => '1',
				'euromail_force_from_email'   => 'not-an-email',
			)
		);

		$this->assertFalse( Euromail_Settings::get( 'euromail_force_from_enabled' ) );

		$found = false;
		foreach ( get_settings_errors( 'euromail_settings' ) as $error ) {
			if ( 'euromail_force_from_invalid' === $error['code'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected an euromail_force_from_invalid settings error.' );
	}

	public function test_force_from_with_empty_email_is_rejected_without_enabling() {
		$this->submit_settings(
			array(
				'euromail_force_from_enabled' => '1',
				'euromail_force_from_email'   => '',
			)
		);

		$this->assertFalse( Euromail_Settings::get( 'euromail_force_from_enabled' ) );
	}

	public function test_force_from_with_valid_email_is_saved_and_enabled() {
		$this->submit_settings(
			array(
				'euromail_force_from_enabled' => '1',
				'euromail_force_from_email'   => 'force@example.com',
			)
		);

		$this->assertTrue( Euromail_Settings::get( 'euromail_force_from_enabled' ) );
		$this->assertSame( 'force@example.com', get_option( 'euromail_force_from_email' ) );
	}
}

class Test_Euromail_Admin_Verify_Key extends WP_Ajax_UnitTestCase {

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		remove_all_filters( 'euromail_verify_key_client' );
		remove_all_actions( 'wp_ajax_euromail_verify_key' );
		parent::tear_down();
	}

	private function set_up_admin_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Euromail_Admin::init() (which registers wp_ajax_euromail_verify_key)
	 * only runs when is_admin() is true, which the PHPUnit CLI context
	 * never is. Wire the action directly so _handleAjax() has something to
	 * call, the same as a real wp-admin request would.
	 */
	private function register_ajax_handler() {
		$admin = new Euromail_Admin();
		add_action( 'wp_ajax_euromail_verify_key', array( $admin, 'ajax_verify_key' ) );
	}

	public function test_resolve_verification_api_key_prefers_submitted_value() {
		update_option( 'euromail_api_key', 'em_live_saved' );

		$resolved = Euromail_Admin::resolve_verification_api_key( array( 'api_key' => 'em_live_typed' ) );

		$this->assertSame( 'em_live_typed', $resolved );
	}

	public function test_resolve_verification_api_key_falls_back_to_saved_key_when_empty() {
		update_option( 'euromail_api_key', 'em_live_saved' );

		$resolved = Euromail_Admin::resolve_verification_api_key( array( 'api_key' => '' ) );

		$this->assertSame( 'em_live_saved', $resolved );

		$resolved_absent = Euromail_Admin::resolve_verification_api_key( array() );

		$this->assertSame( 'em_live_saved', $resolved_absent );
	}

	public function test_ajax_verifies_the_typed_key_not_the_saved_one_and_never_saves_it() {
		$this->set_up_admin_user();
		$this->register_ajax_handler();
		update_option( 'euromail_api_key', 'em_live_saved' );

		$seen_api_key = null;
		add_filter(
			'euromail_verify_key_client',
			function ( $client, $api_key ) use ( &$seen_api_key ) {
				$seen_api_key = $api_key;
				return new Euromail_Test_Fake_Sdk_Client( array( 'email' => 'ok@example.com' ) );
			},
			10,
			2
		);

		$_POST['action']  = 'euromail_verify_key';
		$_POST['nonce']   = wp_create_nonce( 'euromail_verify_key' );
		$_POST['api_key'] = 'em_live_typed';

		try {
			$this->_handleAjax( 'euromail_verify_key' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentionally caught and discarded, see the comment below.
			// Expected: wp_send_json_*() calls wp_die(), which
			// WP_Ajax_UnitTestCase turns into this exception so the test
			// can keep going and inspect the response.
		}

		$this->assertSame( 'em_live_typed', $seen_api_key, 'The typed key must be the one verified.' );
		$this->assertSame( 'em_live_saved', get_option( 'euromail_api_key' ), 'Verifying must never overwrite the saved key.' );

		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
	}

	public function test_ajax_falls_back_to_saved_key_when_field_is_empty() {
		$this->set_up_admin_user();
		$this->register_ajax_handler();
		update_option( 'euromail_api_key', 'em_live_saved' );

		$seen_api_key = null;
		add_filter(
			'euromail_verify_key_client',
			function ( $client, $api_key ) use ( &$seen_api_key ) {
				$seen_api_key = $api_key;
				return new Euromail_Test_Fake_Sdk_Client( array( 'email' => 'ok@example.com' ) );
			},
			10,
			2
		);

		$_POST['action'] = 'euromail_verify_key';
		$_POST['nonce']  = wp_create_nonce( 'euromail_verify_key' );
		unset( $_POST['api_key'] );

		try {
			$this->_handleAjax( 'euromail_verify_key' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentionally caught and discarded, see the comment below.
			// See above.
		}

		$this->assertSame( 'em_live_saved', $seen_api_key );
	}
}

class Test_Euromail_Admin_Log_Row_Actions extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = new Euromail_Admin();
	}

	public function tear_down() {
		remove_all_filters( 'euromail_backends' );
		parent::tear_down();
	}

	private function insert_row( array $overrides = array() ) {
		$defaults = array(
			'status'          => 'failed',
			'idempotency_key' => wp_generate_uuid4(),
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Log action test',
			'payload'         => wp_json_encode(
				array(
					'from'        => 'wordpress@example.com',
					'from_name'   => 'WordPress',
					'to'          => array( 'recipient@example.com' ),
					'cc'          => array(),
					'bcc'         => array(),
					'reply_to'    => '',
					'subject'     => 'Log action test',
					'text_body'   => 'Body text',
					'headers'     => array(),
					'attachments' => array(),
				)
			),
		);

		return Euromail_Logger::create( array_merge( $defaults, $overrides ) );
	}

	public function test_delete_action_removes_the_row_and_returns_the_deleted_notice() {
		$id = $this->insert_row();

		$notice = $this->admin->process_log_row_action( 'delete', $id );

		$this->assertSame( 'deleted', $notice );
		$this->assertNull( Euromail_Logger::get( $id ) );
	}

	public function test_resend_action_with_no_payload_returns_resend_failed_and_leaves_the_row_alone() {
		$id = $this->insert_row( array( 'payload' => null ) );

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resend_failed', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'], 'A resend that never had a payload to work with must not touch the row.' );
	}

	public function test_resend_of_an_already_sent_row_is_refused_and_the_row_is_unchanged() {
		$id           = $this->insert_row(
			array(
				'status'     => 'sent',
				'backend'    => 'api',
				'message_id' => 'msg-already-delivered',
				'attempts'   => 1,
			)
		);
		$original_row = Euromail_Logger::get( $id );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-should-not-happen' ) );
			}
		);

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resend_not_allowed', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( $original_row, $row, 'A resend of an already-sent row must refuse and leave every column exactly as it was.' );
	}

	public function test_resend_of_a_row_currently_sending_is_refused() {
		$id = $this->insert_row( array( 'status' => 'sending' ) );

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resend_not_allowed', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sending', $row['status'], 'A row actively being sent elsewhere must not be resent concurrently.' );
	}

	public function test_resend_action_that_succeeds_immediately_returns_resent_and_marks_the_row_sent() {
		$id = $this->insert_row( array( 'attempts' => 3 ) );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-resend-1' ) );
			}
		);

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resent', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( 'msg-resend-1', $row['message_id'] );
	}

	public function test_resend_action_resets_attempts_to_zero_for_a_fresh_retry_budget() {
		$id = $this->insert_row( array( 'attempts' => Euromail_Queue::MAX_ATTEMPTS ) );

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::failing( new Exception( 'still down' ) ) );
			}
		);

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		// A retryable failure on attempt 1 of a fresh budget is put into
		// 'queued', not immediately failed — proving attempts really was
		// reset to 0, since the row started at MAX_ATTEMPTS (which would
		// have gone straight to 'failed' otherwise).
		$this->assertSame( 'resend_queued', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );
	}

	public function test_resend_action_reuses_the_original_idempotency_key() {
		$id           = $this->insert_row();
		$original_row = Euromail_Logger::get( $id );
		$original_key = $original_row['idempotency_key'];

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-resend-2' ) );
			}
		);

		$this->admin->process_log_row_action( 'resend', $id );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( $original_key, $row['idempotency_key'], 'A resend reuses the original idempotency key: if that send actually reached the server despite the recorded failure, same-key dedupe prevents a duplicate delivery.' );
	}

	public function test_resend_of_a_redacted_row_rehydrates_attachment_content_from_its_stored_path() {
		$path = tempnam( sys_get_temp_dir(), 'euromail-admin-test-' );
		file_put_contents( $path, 'attachment bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$id = $this->insert_row(
			array(
				'payload' => wp_json_encode(
					array(
						'from'        => 'wordpress@example.com',
						'from_name'   => 'WordPress',
						'to'          => array( 'recipient@example.com' ),
						'cc'          => array(),
						'bcc'         => array(),
						'reply_to'    => '',
						'subject'     => 'Log action test',
						'text_body'   => 'Body text',
						'headers'     => array(),
						// No 'content' key: a redacted failed-row payload,
						// as euromail_store_body=on produces.
						'attachments' => array(
							array(
								'filename'     => 'file.txt',
								'content_type' => 'text/plain',
								'path'         => $path,
							),
						),
					)
				),
			)
		);

		$backend = new Euromail_Test_Capturing_Backend( 'msg-resend-rehydrated' );
		add_filter(
			'euromail_backends',
			function () use ( $backend ) {
				return array( 'fake' => $backend );
			}
		);

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resent', $notice );
		$this->assertSame(
			base64_encode( 'attachment bytes' ),
			$backend->captured_email['attachments'][0]['content'],
			'A resend of a redacted payload must re-read the attachment from its stored path before sending.'
		);

		unlink( $path );
	}

	public function test_resend_is_refused_with_a_named_error_when_an_attachment_file_is_gone() {
		$missing_path = sys_get_temp_dir() . '/euromail-admin-test-missing-' . wp_generate_uuid4() . '.txt';

		$id = $this->insert_row(
			array(
				'payload' => wp_json_encode(
					array(
						'from'        => 'wordpress@example.com',
						'from_name'   => 'WordPress',
						'to'          => array( 'recipient@example.com' ),
						'cc'          => array(),
						'bcc'         => array(),
						'reply_to'    => '',
						'subject'     => 'Log action test',
						'text_body'   => 'Body text',
						'headers'     => array(),
						'attachments' => array(
							array(
								'filename'     => 'gone.txt',
								'content_type' => 'text/plain',
								'path'         => $missing_path,
							),
						),
					)
				),
			)
		);

		add_filter(
			'euromail_backends',
			function () {
				return array( 'fake' => Euromail_Test_Fake_Backend::succeeding( 'msg-should-not-happen' ) );
			}
		);

		$notice = $this->admin->process_log_row_action( 'resend', $id );

		$this->assertSame( 'resend_missing_attachment', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'failed', $row['status'], 'A resend refused for a missing attachment must leave the row exactly as it was, not silently send without the attachment.' );
	}
}

/**
 * Minimal fake of the SDK's Client, only exposing what
 * Euromail_Admin::refresh_status_from_api() touches (->emails->get($id)).
 */
class Euromail_Test_Fake_Email_Details {

	public $status;
	public $events;

	public function __construct( $status, array $events ) {
		$this->status = $status;
		$this->events = $events;
	}
}

class Euromail_Test_Fake_Emails_Resource {

	private $details;
	private $exception;

	public static function returning( Euromail_Test_Fake_Email_Details $details ) {
		$resource          = new self();
		$resource->details = $details;
		return $resource;
	}

	public static function failing( Throwable $exception ) {
		$resource            = new self();
		$resource->exception = $exception;
		return $resource;
	}

	public function get( $id ) {
		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return $this->details;
	}
}

class Euromail_Test_Fake_Emails_Client {

	public $emails;

	public function __construct( Euromail_Test_Fake_Emails_Resource $fake ) {
		$this->emails = $fake;
	}
}

class Test_Euromail_Admin_Refresh_Status extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		update_option( 'euromail_api_key', 'em_live_test' );
		$this->admin = new Euromail_Admin();
	}

	public function tear_down() {
		delete_option( 'euromail_api_key' );
		remove_all_filters( 'euromail_refresh_status_client' );
		parent::tear_down();
	}

	private function insert_row( array $overrides = array() ) {
		$defaults = array(
			'status'          => 'sent',
			'backend'         => 'api',
			'api_id'          => 'api-uuid-123',
			'idempotency_key' => wp_generate_uuid4(),
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Refresh status test',
		);

		return Euromail_Logger::create( array_merge( $defaults, $overrides ) );
	}

	public function test_refreshes_status_and_events_from_the_api() {
		$id = $this->insert_row( array( 'status' => 'sent' ) );

		add_filter(
			'euromail_refresh_status_client',
			function () {
				return new Euromail_Test_Fake_Emails_Client(
					Euromail_Test_Fake_Emails_Resource::returning(
						new Euromail_Test_Fake_Email_Details(
							'delivered',
							array(
								array(
									'type'      => 'delivered',
									'timestamp' => '2026-01-01T00:00:00Z',
								),
							)
						)
					)
				);
			}
		);

		$notice = $this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'refreshed', $notice );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'delivered', $row['status'] );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events );
		$this->assertSame( 'delivered', $events[0]['type'] );
	}

	public function test_refresh_is_not_available_for_a_row_not_sent_through_the_api() {
		$id = $this->insert_row(
			array(
				'backend' => 'smtp',
				'api_id'  => null,
			)
		);

		$notice = $this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'refresh_not_available', $notice );
	}

	public function test_refresh_failed_when_the_sdk_call_throws_and_leaves_the_row_untouched() {
		$id = $this->insert_row();

		add_filter(
			'euromail_refresh_status_client',
			function () {
				return new Euromail_Test_Fake_Emails_Client(
					Euromail_Test_Fake_Emails_Resource::failing( new Exception( 'network error' ) )
				);
			}
		);

		$before = Euromail_Logger::get( $id );
		$notice = $this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'refresh_failed', $notice );
		$this->assertSame( $before, Euromail_Logger::get( $id ), 'A failed refresh must leave the row untouched.' );
	}

	// -- Refresh must obey the same promotion rules as the webhook receiver --

	private function fake_client_returning( $status, array $events ) {
		return function () use ( $status, $events ) {
			return new Euromail_Test_Fake_Emails_Client(
				Euromail_Test_Fake_Emails_Resource::returning( new Euromail_Test_Fake_Email_Details( $status, $events ) )
			);
		};
	}

	public function test_refresh_does_not_demote_a_higher_local_status() {
		$id = $this->insert_row( array( 'status' => 'opened' ) );

		add_filter( 'euromail_refresh_status_client', $this->fake_client_returning( 'delivered', array() ) );

		$this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'opened', Euromail_Logger::get( $id )['status'], 'The API reporting a lower-ranked status must not demote a row already further along.' );
	}

	public function test_refresh_never_overwrites_a_bounced_status() {
		$id = $this->insert_row( array( 'status' => 'bounced' ) );

		add_filter( 'euromail_refresh_status_client', $this->fake_client_returning( 'delivered', array() ) );

		$this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'bounced', Euromail_Logger::get( $id )['status'], 'bounced is permanent — the API reporting anything else must not overwrite it.' );
	}

	public function test_refresh_ignores_an_unrecognized_api_status() {
		$id = $this->insert_row( array( 'status' => 'sent' ) );

		add_filter( 'euromail_refresh_status_client', $this->fake_client_returning( 'some-future-status', array() ) );

		$this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'sent', Euromail_Logger::get( $id )['status'], 'An unrecognized status from the API must be ignored, not applied verbatim.' );
	}

	public function test_a_local_bounced_event_survives_a_refresh_whose_api_response_has_empty_events() {
		$id = $this->insert_row(
			array(
				'status' => 'bounced',
				'events' => wp_json_encode(
					array(
						array(
							'type'      => 'bounced',
							'timestamp' => '2026-01-01T00:00:00Z',
						),
					)
				),
			)
		);

		add_filter( 'euromail_refresh_status_client', $this->fake_client_returning( 'bounced', array() ) );

		$notice = $this->admin->refresh_status_from_api( $id );

		$this->assertSame( 'refreshed', $notice );

		$events = json_decode( Euromail_Logger::get( $id )['events'], true );
		$this->assertCount( 1, $events, 'The webhook-recorded local event must survive a refresh whose API response has no events at all.' );
		$this->assertSame( 'bounced', $events[0]['type'] );
	}

	public function test_refresh_merges_new_api_events_with_existing_local_events_without_duplicating() {
		$id = $this->insert_row(
			array(
				'status' => 'sent',
				'events' => wp_json_encode(
					array(
						array(
							'type'      => 'sent',
							'timestamp' => '2026-01-01T00:00:00Z',
						),
					)
				),
			)
		);

		add_filter(
			'euromail_refresh_status_client',
			$this->fake_client_returning(
				'delivered',
				array(
					// Same type+timestamp as the existing local event: must not be duplicated.
					array(
						'type'      => 'sent',
						'timestamp' => '2026-01-01T00:00:00Z',
					),
					// A genuinely new event: must be added.
					array(
						'type'      => 'delivered',
						'timestamp' => '2026-01-02T00:00:00Z',
					),
				)
			)
		);

		$this->admin->refresh_status_from_api( $id );

		$events = json_decode( Euromail_Logger::get( $id )['events'], true );
		$this->assertCount( 2, $events, 'A duplicate type+timestamp must be deduped; a genuinely new event must be added.' );
	}
}

/**
 * Guarantees under test:
 * - Resend/Delete/Refresh status are dispatched from a `load-{$log_page_hook}`
 *   action, not from the Log page's own render callback — admin.php has
 *   already sent the admin chrome (doctype, head, admin bar) via
 *   admin-header.php by the time a submenu page's render callback runs, so
 *   a wp_safe_redirect() from inside it can no longer succeed. load- fires
 *   early enough.
 */
class Test_Euromail_Admin_Log_Page_Actions_Wiring extends WP_UnitTestCase {

	public function tear_down() {
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
		parent::tear_down();
	}

	public function test_add_menu_pages_wires_a_load_hook_for_log_page_actions() {
		// add_submenu_page() itself checks the current user's capability
		// and returns false (no hook suffix at all) without one.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$admin = new Euromail_Admin();
		$admin->add_menu_pages();

		$this->assertNotEmpty( $admin->log_page_hook, 'add_submenu_page() must return a usable hook suffix.' );
		$this->assertNotFalse(
			has_action( "load-{$admin->log_page_hook}", array( $admin, 'maybe_handle_log_page_actions' ) ),
			'maybe_handle_log_page_actions() must be wired to load-{hook}, which runs before admin-header.php sends any output.'
		);
	}
}
