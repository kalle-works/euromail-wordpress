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
	 * Event types that promote a row's status, ranked low to high. A later
	 * event with a higher rank promotes the status; a lower-ranked event
	 * arriving out of order (e.g. 'delivered' after 'opened') never demotes
	 * it. 'bounced' and 'complained' are handled separately: they always
	 * win, and once set are never overwritten by anything. 'deferred' is
	 * recorded in the events timeline but never changes status at all.
	 *
	 * @var array<string,int>
	 */
	const STATUS_RANK = array(
		'sent'      => 1,
		'delivered' => 2,
		'opened'    => 3,
		'clicked'   => 4,
	);

	/**
	 * Statuses that, once reached, no later event may change.
	 *
	 * @var string[]
	 */
	const TERMINAL_STATUSES = array( 'bounced', 'complained' );

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

		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( 'X-Euromail-Signature' );

		if ( '' === $signature || ! EUROMAIL_SDK_LOADED
			|| ! EuroMail\Webhooks\WebhookSignature::verify( $body, $signature, $secret, 300 ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Euromail: invalid webhook signature.', 'euromail' ) ),
				401
			);
		}

		$event_type = sanitize_key( (string) $request->get_header( 'X-Euromail-Event' ) );
		$payload    = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			// Correctly signed but unparseable body: nothing to apply, but
			// the signature was genuine, so this is not the sender's fault
			// to retry over.
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$api_id = isset( $payload['id'] ) ? (string) $payload['id'] : '';

		if ( '' === $api_id ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$row = Euromail_Logger::get_by_api_id( $api_id );

		if ( ! $row ) {
			// Payloads are additive and the log is not the source of truth
			// for every email euromail.dev has ever sent (a row may have
			// been pruned by retention, or belong to a send this site
			// didn't originate) — an unmatched event is not an error.
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$this->apply_event( $row, $event_type, $payload );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Append an event to a row's timeline and promote its status.
	 *
	 * @param array  $row        Log row.
	 * @param string $event_type Sanitized event type from the X-Euromail-Event header.
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
				'status' => self::promote_status( $row['status'], $event_type ),
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

	/**
	 * Compute the row's next status for an incoming event, with no
	 * demotion: a 'bounced'/'complained' status is permanent once set; a
	 * 'bounced'/'complained' event always wins over any prior status; a
	 * 'deferred' event never changes status; any other recognized event
	 * only promotes status forward along the sent→delivered→opened→clicked
	 * rank, never backward.
	 *
	 * @param string $current_status Row's current status.
	 * @param string $event_type     Sanitized event type.
	 * @return string
	 */
	private static function promote_status( $current_status, $event_type ) {
		if ( in_array( $current_status, self::TERMINAL_STATUSES, true ) ) {
			return $current_status;
		}

		if ( in_array( $event_type, self::TERMINAL_STATUSES, true ) ) {
			return $event_type;
		}

		if ( 'deferred' === $event_type ) {
			return $current_status;
		}

		if ( ! isset( self::STATUS_RANK[ $event_type ] ) ) {
			return $current_status;
		}

		$current_rank = isset( self::STATUS_RANK[ $current_status ] ) ? self::STATUS_RANK[ $current_status ] : 0;

		return self::STATUS_RANK[ $event_type ] > $current_rank ? $event_type : $current_status;
	}
}
