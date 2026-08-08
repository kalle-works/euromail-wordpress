<?php
/**
 * Tests for Euromail_Webhook_Controller.
 *
 * Guarantees under test:
 * - A validly signed event applies (event appended, status promoted) and
 *   returns 200.
 * - A missing/invalid signature returns 401 and touches nothing.
 * - A signature whose timestamp is outside the 300s tolerance returns 401,
 *   even if the HMAC itself is otherwise correct for that timestamp.
 * - No webhook secret configured returns 501.
 * - An event for an email id not in the log returns 200 and touches no
 *   row (payloads are additive, an unknown email is not an error).
 * - Status promotion matrix: delivered->opened promotes; opened->delivered
 *   does NOT demote; bounced overrides delivered; bounced is never
 *   overwritten by a later event; deferred never changes status (but is
 *   still recorded in the events timeline).
 *
 * @package Euromail
 */

class Test_Euromail_Webhook_Controller extends WP_UnitTestCase {

	/**
	 * @var WP_REST_Server
	 */
	private $server;

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found
		do_action( 'rest_api_init', $this->server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		delete_option( 'euromail_webhook_secret' );

		parent::tear_down();
	}

	private function insert_row( array $overrides = array() ) {
		$defaults = array(
			'status'          => 'sent',
			'backend'         => 'api',
			'api_id'          => 'api-uuid-123',
			'idempotency_key' => wp_generate_uuid4(),
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Webhook test',
		);

		return Euromail_Logger::create( array_merge( $defaults, $overrides ) );
	}

	/**
	 * Sign a payload exactly the way EuroMail\Webhooks\WebhookSignature::verify()
	 * expects: hash_hmac('sha256', "{ts}.{body}", secret), formatted as
	 * "t={ts},v1={hex}".
	 *
	 * @param string   $body      Raw request body.
	 * @param string   $secret    Webhook secret.
	 * @param int|null $timestamp Unix timestamp to sign with; defaults to now.
	 * @return string
	 */
	private function sign( $body, $secret, $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = time();
		}

		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		return 't=' . $timestamp . ',v1=' . $signature;
	}

	/**
	 * @param string      $body       Raw request body.
	 * @param string|null $signature  X-Euromail-Signature header value, or null to omit it.
	 * @param string|null $event_type X-Euromail-Event header value, or null to omit it.
	 * @return WP_REST_Response
	 */
	private function dispatch( $body, $signature, $event_type ) {
		$request = new WP_REST_Request( 'POST', '/euromail/v1/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );

		if ( null !== $signature ) {
			$request->set_header( 'X-Euromail-Signature', $signature );
		}

		if ( null !== $event_type ) {
			$request->set_header( 'X-Euromail-Event', $event_type );
		}

		$request->set_body( $body );

		return $this->server->dispatch( $request );
	}

	public function test_valid_signature_appends_event_and_promotes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'sent' ) );
		$body = wp_json_encode(
			array(
				'id'        => 'api-uuid-123',
				'timestamp' => '2026-01-01T00:00:00Z',
			)
		);

		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'delivered', $row['status'] );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events );
		$this->assertSame( 'delivered', $events[0]['type'] );
		$this->assertSame( '2026-01-01T00:00:00Z', $events[0]['timestamp'] );
	}

	public function test_missing_signature_header_returns_401_and_touches_nothing() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id      = $this->insert_row();
		$before  = Euromail_Logger::get( $id );
		$body    = wp_json_encode( array( 'id' => 'api-uuid-123' ) );
		$response = $this->dispatch( $body, null, 'delivered' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	public function test_bad_signature_returns_401() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$body     = wp_json_encode( array( 'id' => 'api-uuid-123' ) );
		$response = $this->dispatch( $body, 't=' . time() . ',v1=' . str_repeat( '0', 64 ), 'delivered' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_expired_timestamp_returns_401_even_with_a_correct_hmac() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$body      = wp_json_encode( array( 'id' => 'api-uuid-123' ) );
		$stale_ts  = time() - 301; // Just past the 300s tolerance.
		$response  = $this->dispatch( $body, $this->sign( $body, 'shh', $stale_ts ), 'delivered' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_no_secret_configured_returns_501() {
		delete_option( 'euromail_webhook_secret' );

		$body     = wp_json_encode( array( 'id' => 'api-uuid-123' ) );
		$response = $this->dispatch( $body, $this->sign( $body, 'anything' ), 'delivered' );

		$this->assertSame( 501, $response->get_status() );
	}

	public function test_unknown_email_id_returns_200_and_touches_no_row() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id     = $this->insert_row( array( 'api_id' => 'a-real-id' ) );
		$before = Euromail_Logger::get( $id );

		$body     = wp_json_encode( array( 'id' => 'a-completely-different-id' ) );
		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	// -- Status promotion matrix --

	public function test_delivered_then_opened_promotes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'delivered' ) );
		$body = wp_json_encode( array( 'id' => 'api-uuid-123' ) );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'opened' );

		$this->assertSame( 'opened', Euromail_Logger::get( $id )['status'] );
	}

	public function test_opened_then_delivered_does_not_demote_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'opened' ) );
		$body = wp_json_encode( array( 'id' => 'api-uuid-123' ) );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 'opened', Euromail_Logger::get( $id )['status'], 'A lower-ranked event arriving after a higher one must not demote status.' );
	}

	public function test_bounced_overrides_delivered() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'delivered' ) );
		$body = wp_json_encode( array( 'id' => 'api-uuid-123' ) );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'bounced' );

		$this->assertSame( 'bounced', Euromail_Logger::get( $id )['status'] );
	}

	public function test_bounced_is_never_overwritten_by_a_later_event() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'bounced' ) );
		$body = wp_json_encode( array( 'id' => 'api-uuid-123' ) );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'opened' );

		$this->assertSame( 'bounced', Euromail_Logger::get( $id )['status'], 'bounced must be permanent — no later event may overwrite it.' );
	}

	public function test_deferred_is_recorded_but_never_changes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'sent' ) );
		$body = wp_json_encode( array( 'id' => 'api-uuid-123' ) );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'deferred' );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sent', $row['status'] );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events );
		$this->assertSame( 'deferred', $events[0]['type'] );
	}
}
