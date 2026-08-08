<?php
/**
 * REST receiver for euromail.dev delivery event webhooks.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers POST /wp-json/euromail/v1/webhook and applies incoming delivery
 * events (sent/delivered/bounced/deferred/opened/clicked/complained) to the
 * matching log row.
 */
class Euromail_Webhook_Controller {

	/**
	 * A webhook whose api_id matches no row yet, arriving within this many
	 * seconds of its own signed timestamp, is treated as delivered before
	 * our own row exists (a plausible race: euromail.dev fired the webhook
	 * before our own write committed, or before replication caught up) and
	 * gets a 503 so euromail.dev's retry ladder (1 minute, 5 minutes, ...)
	 * redelivers it once the row exists. An unmatched event older than this
	 * is presumed genuinely unrelated to this site and is dropped with 200.
	 */
	const UNMATCHED_RETRY_WINDOW = 120;

	/**
	 * Seconds to wait for the per-row named lock before giving up and
	 * asking the sender to retry.
	 */
	const LOCK_TIMEOUT = 2;

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook REST route.
	 */
	public function register_routes() {
		register_rest_route(
			'euromail/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated via the signature header, not WordPress capabilities.
			)
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$secret = (string) Euromail_Settings::get( 'euromail_webhook_secret' );

		if ( '' === $secret ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: no webhook secret configured.', 'euromail' ) ),
				501
			);
		}

		/**
		 * Filters whether the Euromail SDK is considered loaded, for this
		 * check only. Defaults to the real EUROMAIL_SDK_LOADED constant;
		 * lets tests exercise the "SDK unavailable" path without needing
		 * to redefine that load-time constant.
		 *
		 * @param bool $sdk_loaded Default EUROMAIL_SDK_LOADED.
		 */
		if ( ! apply_filters( 'euromail_webhook_sdk_loaded', EUROMAIL_SDK_LOADED ) ) {
			// Distinct from an invalid signature (401): the secret is
			// configured, but there is no way to verify anything against
			// it, so this is a server misconfiguration the sender should
			// retry, not a rejected/untrusted request.
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: the Euromail SDK is not installed; cannot verify webhook signatures.', 'euromail' ) ),
				503
			);
		}

		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( 'X-Euromail-Signature' );

		if ( '' === $signature || ! EuroMail\Webhooks\WebhookSignature::verify( $body, $signature, $secret, 300 ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: invalid webhook signature.', 'euromail' ) ),
				401
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			// Correctly signed but unparseable body: nothing to apply, but
			// the signature was genuine, so this is not the sender's fault
			// to retry over.
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		// The event type MUST come from the signed body, never from the
		// X-Euromail-Event header: the header is not covered by the HMAC
		// signature, so a party able to intercept/replay a genuinely signed
		// request could otherwise swap in an arbitrary header value (e.g.
		// turning a signed 'delivered' event into a forged 'complained'
		// one) without invalidating the signature. The header may still be
		// used for logging/diagnostics, never for deciding anything.
		$event_type = isset( $payload['event'] ) ? sanitize_key( (string) $payload['event'] ) : '';

		$api_id = isset( $payload['email_id'] ) ? (string) $payload['email_id'] : '';

		if ( '' === $api_id ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$row = Euromail_Logger::get_by_api_id( $api_id );

		if ( ! $row ) {
			return $this->handle_unmatched_event( $signature );
		}

		if ( ! $this->apply_event_with_lock( (int) $row['id'], $event_type, $payload ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: could not acquire a lock on this log row; please retry.', 'euromail' ) ),
				503
			);
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * An event whose api_id matches no local row: euromail.dev's own
	 * webhook can plausibly race ahead of this site's own write (the
	 * SentEmail response that would have recorded api_id hasn't been
	 * persisted yet, or a read-replica lag). Payloads are also additive and
	 * the log is not the source of truth for every email euromail.dev has
	 * ever sent (a row may have been pruned by retention, or belong to a
	 * send this site didn't originate) — so this is only treated as a race
	 * (503, please retry) when the signed timestamp is recent; an older
	 * unmatched event is presumed genuinely unrelated and dropped (200).
	 *
	 * @param string $signature_header Verified X-Euromail-Signature header value.
	 * @return WP_REST_Response
	 */
	private function handle_unmatched_event( $signature_header ) {
		$signed_at = self::signature_timestamp( $signature_header );

		if ( null !== $signed_at && ( time() - $signed_at ) < self::UNMATCHED_RETRY_WINDOW ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: no matching log row yet; please retry.', 'euromail' ) ),
				503
			);
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Extract the unix timestamp ("t=...") a webhook signature was
	 * computed with.
	 *
	 * @param string $signature_header Raw X-Euromail-Signature header, e.g. "t=1700000000,v1=...".
	 * @return int|null
	 */
	private static function signature_timestamp( $signature_header ) {
		if ( preg_match( '/(?:^|,)\s*t=(\d+)/', $signature_header, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * Apply an event to a row while holding a MySQL named lock keyed to
	 * that row, so two overlapping webhook deliveries for the same row (a
	 * genuine retry racing the original, or two distinct events arriving
	 * back to back) can never read-modify-write the events/status columns
	 * concurrently and silently drop one of the two updates.
	 *
	 * @param int    $row_id     Log row ID.
	 * @param string $event_type Event type from the signed body.
	 * @param array  $payload    Decoded JSON payload.
	 * @return bool False when the lock could not be acquired in time.
	 */
	private function apply_event_with_lock( $row_id, $event_type, array $payload ) {
		$lock_key = 'euromail_log_' . $row_id;

		/**
		 * Filters the callable used to acquire the per-row lock. Returning
		 * a non-null callable overrides the real MySQL GET_LOCK() call,
		 * letting tests exercise the lock-timeout path without real
		 * concurrency.
		 *
		 * @param callable|null $acquire Default null (real GET_LOCK()). Callable signature: function( string $key ): bool.
		 */
		$acquire = apply_filters( 'euromail_webhook_lock_acquire', null );

		if ( null === $acquire ) {
			$acquire = array( $this, 'acquire_named_lock' );
		}

		/**
		 * Filters the callable used to release the per-row lock. See
		 * euromail_webhook_lock_acquire.
		 *
		 * @param callable|null $release Default null (real RELEASE_LOCK()). Callable signature: function( string $key ): void.
		 */
		$release = apply_filters( 'euromail_webhook_lock_release', null );

		if ( null === $release ) {
			$release = array( $this, 'release_named_lock' );
		}

		if ( ! call_user_func( $acquire, $lock_key ) ) {
			return false;
		}

		try {
			$row = Euromail_Logger::get( $row_id );

			if ( $row ) {
				$this->apply_event( $row, $event_type, $payload );
			}
		} finally {
			call_user_func( $release, $lock_key );
		}

		return true;
	}

	/**
	 * Acquire a MySQL named lock. The real (non-test) implementation.
	 *
	 * @param string $key Lock key.
	 * @return bool
	 */
	public function acquire_named_lock( $key ) {
		global $wpdb;

		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, self::LOCK_TIMEOUT ) );
	}

	/**
	 * Release a MySQL named lock. The real (non-test) implementation.
	 *
	 * @param string $key Lock key.
	 */
	public function release_named_lock( $key ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}

	/**
	 * Append an event to a row's timeline and promote its status. Called
	 * only while holding that row's named lock. A body without a
	 * recognized event type (blank/unsigned/unknown) still gets appended
	 * to the timeline for the audit trail, but Euromail_Status_Promoter
	 * leaves the status untouched for an event type it doesn't recognize.
	 *
	 * @param array  $row        Log row.
	 * @param string $event_type Event type read from the signed body (may be '').
	 * @param array  $payload    Decoded JSON payload.
	 */
	private function apply_event( array $row, $event_type, array $payload ) {
		$events   = self::decode_events( $row['events'] );
		$events[] = array(
			'type'      => $event_type,
			'timestamp' => isset( $payload['timestamp'] ) ? $payload['timestamp'] : current_time( 'mysql' ),
			'raw'       => $payload,
		);

		Euromail_Logger::update(
			$row['id'],
			array(
				'events' => wp_json_encode( $events ),
				'status' => Euromail_Status_Promoter::promote( $row['status'], $event_type ),
			)
		);
	}

	/**
	 * Decode a row's stored events JSON into an array, tolerating a
	 * missing/unreadable value.
	 *
	 * @param string|null $events_json Raw 'events' column value.
	 * @return array
	 */
	private static function decode_events( $events_json ) {
		if ( empty( $events_json ) ) {
			return array();
		}

		$decoded = json_decode( $events_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
