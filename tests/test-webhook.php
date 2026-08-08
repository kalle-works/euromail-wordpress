<?php
/**
 * Tests for Euromail_Webhook_Controller.
 *
 * Guarantees under test:
 * - A validly signed event applies (event appended, status promoted) and
 *   returns 200.
 * - The event type is read from the signed body's "event" field, never the
 *   X-Euromail-Event header — the header is not covered by the HMAC, so a
 *   forged header on an otherwise genuinely signed request must not be able
 *   to change which status a row is promoted to.
 * - A signed body with no "event" field still gets appended to the
 *   timeline, but never changes status.
 * - A missing/invalid signature returns 401 and touches nothing.
 * - A signature whose timestamp is outside the 300s tolerance returns 401,
 *   even if the HMAC itself is otherwise correct for that timestamp.
 * - No webhook secret configured returns 501.
 * - A secret configured but the SDK unavailable returns 503 (distinct from
 *   401 — this is a server misconfiguration, not a rejected request).
 * - An event for an email id not in the log returns 200 (dropped) when the
 *   signed timestamp is stale, or 503 (please retry) when it is fresh
 *   enough to plausibly be a race with this site's own not-yet-committed
 *   write.
 * - Two overlapping requests for the same row apply serially, never losing
 *   one event to a lost update; a lock that cannot be acquired in time
 *   returns 503.
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
		remove_all_filters( 'euromail_webhook_lock_acquire' );
		remove_all_filters( 'euromail_webhook_lock_release' );
		remove_all_filters( 'euromail_webhook_sdk_loaded' );

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
	 * Build a real webhook body the way euromail.dev's own worker
	 * constructs it (confirmed against fire_webhook.rs / process_bounce.rs
	 * / send_email.rs): "event" for the event type, "email_id" for the
	 * email identifier — NOT "type"/"id".
	 *
	 * @param string $event    Event type.
	 * @param string $email_id API id to target.
	 * @param array  $extra    Additional payload fields.
	 * @return string
	 */
	private function real_body( $event, $email_id, array $extra = array() ) {
		return wp_json_encode(
			array_merge(
				array(
					'event'    => $event,
					'email_id' => $email_id,
				),
				$extra
			)
		);
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
		$body = $this->real_body( 'delivered', 'api-uuid-123', array( 'timestamp' => '2026-01-01T00:00:00Z' ) );

		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'delivered', $row['status'] );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events );
		$this->assertSame( 'delivered', $events[0]['type'] );
		$this->assertSame( '2026-01-01T00:00:00Z', $events[0]['timestamp'] );
	}

	public function test_event_type_comes_from_the_signed_body_not_the_header() {
		update_option( 'euromail_webhook_secret', 'shh' );

		// A genuinely signed 'delivered' body, replayed with a forged
		// X-Euromail-Event: complained header — the header is not part of
		// the HMAC input, so this must not be able to mark the row
		// complained.
		$id   = $this->insert_row( array( 'status' => 'sent' ) );
		$body = $this->real_body( 'delivered', 'api-uuid-123' );

		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'complained' );

		$this->assertSame( 200, $response->get_status() );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'delivered', $row['status'], 'The signed body\'s event type must win, never the unsigned X-Euromail-Event header.' );
		$this->assertNotSame( 'complained', $row['status'] );
	}

	public function test_body_without_an_event_field_appends_a_timeline_entry_but_never_changes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'sent' ) );
		$body = wp_json_encode( array( 'email_id' => 'api-uuid-123' ) ); // No "event" key at all.

		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sent', $row['status'], 'A body without a recognized event type must never change status, even with a header present.' );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events, 'The event must still be appended to the timeline for the audit trail.' );
	}

	public function test_missing_signature_header_returns_401_and_touches_nothing() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id       = $this->insert_row();
		$before   = Euromail_Logger::get( $id );
		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		$response = $this->dispatch( $body, null, 'delivered' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	public function test_bad_signature_returns_401() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		$response = $this->dispatch( $body, 't=' . time() . ',v1=' . str_repeat( '0', 64 ), 'delivered' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_expired_timestamp_returns_401_even_with_a_correct_hmac() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		$stale_ts = time() - 301; // Just past the 300s tolerance.
		$response = $this->dispatch( $body, $this->sign( $body, 'shh', $stale_ts ), 'delivered' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_no_secret_configured_returns_501() {
		delete_option( 'euromail_webhook_secret' );

		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		$response = $this->dispatch( $body, $this->sign( $body, 'anything' ), 'delivered' );

		$this->assertSame( 501, $response->get_status() );
	}

	public function test_secret_configured_but_sdk_missing_returns_503_not_401() {
		update_option( 'euromail_webhook_secret', 'shh' );
		add_filter( 'euromail_webhook_sdk_loaded', '__return_false' );

		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		// Deliberately unsigned/garbage: if the SDK-missing check ran
		// AFTER signature verification instead of before, this would
		// still 401, masking the 503 this test is asserting.
		$response = $this->dispatch( $body, 'garbage-signature', 'delivered' );

		$this->assertSame( 503, $response->get_status() );

		remove_filter( 'euromail_webhook_sdk_loaded', '__return_false' );
	}

	public function test_secret_and_sdk_both_available_still_verifies_the_signature_normally() {
		update_option( 'euromail_webhook_secret', 'shh' );
		add_filter( 'euromail_webhook_sdk_loaded', '__return_true' );

		$this->insert_row();
		$body = $this->real_body( 'delivered', 'api-uuid-123' );
		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );

		remove_filter( 'euromail_webhook_sdk_loaded', '__return_true' );
	}

	// -- Early-webhook race (unmatched api_id) --

	public function test_fresh_unmatched_event_returns_503_so_the_sender_retries() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id     = $this->insert_row( array( 'api_id' => 'a-real-id' ) );
		$before = Euromail_Logger::get( $id );

		$body      = $this->real_body( 'delivered', 'a-completely-different-id' );
		$fresh_now = time();
		$response  = $this->dispatch( $body, $this->sign( $body, 'shh', $fresh_now ), 'delivered' );

		$this->assertSame( 503, $response->get_status(), 'An unmatched event with a fresh signed timestamp must return 503 so the sender\'s retry ladder redelivers once the row exists.' );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	public function test_stale_unmatched_event_returns_200_and_is_dropped() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id     = $this->insert_row( array( 'api_id' => 'a-real-id' ) );
		$before = Euromail_Logger::get( $id );

		$body      = $this->real_body( 'delivered', 'a-completely-different-id' );
		$stale_now = time() - 130; // Past the 120s unmatched-retry window, still within the 300s signature tolerance.
		$response  = $this->dispatch( $body, $this->sign( $body, 'shh', $stale_now ), 'delivered' );

		$this->assertSame( 200, $response->get_status(), 'An unmatched event whose signed timestamp is no longer fresh must be dropped, not retried forever.' );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	// -- Concurrent webhook race (named lock) --

	public function test_a_lock_that_cannot_be_acquired_returns_503() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id = $this->insert_row( array( 'status' => 'sent' ) );

		add_filter(
			'euromail_webhook_lock_acquire',
			function () {
				return function ( $key ) {
					return false; // Simulate GET_LOCK() timing out.
				};
			}
		);

		$body     = $this->real_body( 'delivered', 'api-uuid-123' );
		$response = $this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'sent', Euromail_Logger::get( $id )['status'], 'A lock timeout must not apply the event.' );
	}

	public function test_the_lock_is_released_after_a_successful_apply() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id = $this->insert_row( array( 'status' => 'sent' ) );

		$released_keys = array();
		add_filter(
			'euromail_webhook_lock_release',
			function () use ( &$released_keys ) {
				return function ( $key ) use ( &$released_keys ) {
					$released_keys[] = $key;
				};
			}
		);

		$body = $this->real_body( 'delivered', 'api-uuid-123' );
		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( array( 'euromail_log_' . $id ), $released_keys );
	}

	public function test_unknown_email_id_returns_200_and_touches_no_row() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id     = $this->insert_row( array( 'api_id' => 'a-real-id' ) );
		$before = Euromail_Logger::get( $id );

		// Old enough to fall outside the unmatched-retry window, so this
		// exercises the "genuinely unrelated, drop it" path rather than the
		// early-webhook race path.
		$body      = $this->real_body( 'delivered', 'a-completely-different-id' );
		$stale_now = time() - 130;
		$response  = $this->dispatch( $body, $this->sign( $body, 'shh', $stale_now ), 'delivered' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $before, Euromail_Logger::get( $id ) );
	}

	// -- Status promotion matrix --

	public function test_delivered_then_opened_promotes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'delivered' ) );
		$body = $this->real_body( 'opened', 'api-uuid-123' );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'opened' );

		$this->assertSame( 'opened', Euromail_Logger::get( $id )['status'] );
	}

	public function test_opened_then_delivered_does_not_demote_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'opened' ) );
		$body = $this->real_body( 'delivered', 'api-uuid-123' );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'delivered' );

		$this->assertSame( 'opened', Euromail_Logger::get( $id )['status'], 'A lower-ranked event arriving after a higher one must not demote status.' );
	}

	public function test_bounced_overrides_delivered() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'delivered' ) );
		$body = $this->real_body( 'bounced', 'api-uuid-123' );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'bounced' );

		$this->assertSame( 'bounced', Euromail_Logger::get( $id )['status'] );
	}

	public function test_bounced_is_never_overwritten_by_a_later_event() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'bounced' ) );
		$body = $this->real_body( 'opened', 'api-uuid-123' );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'opened' );

		$this->assertSame( 'bounced', Euromail_Logger::get( $id )['status'], 'bounced must be permanent — no later event may overwrite it.' );
	}

	public function test_deferred_is_recorded_but_never_changes_status() {
		update_option( 'euromail_webhook_secret', 'shh' );

		$id   = $this->insert_row( array( 'status' => 'sent' ) );
		$body = $this->real_body( 'deferred', 'api-uuid-123' );

		$this->dispatch( $body, $this->sign( $body, 'shh' ), 'deferred' );

		$row = Euromail_Logger::get( $id );
		$this->assertSame( 'sent', $row['status'] );

		$events = json_decode( $row['events'], true );
		$this->assertCount( 1, $events );
		$this->assertSame( 'deferred', $events[0]['type'] );
	}
}
