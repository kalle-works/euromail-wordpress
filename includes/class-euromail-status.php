<?php
/**
 * Shared status-promotion rules for delivery events.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both the webhook receiver (an event arriving asynchronously) and the
 * "Refresh status" admin action (an explicit sync against euromail.dev's
 * own current record) need to apply an incoming status to a row without
 * ever moving it backwards or overwriting a terminal outcome — the same
 * rule regardless of which path the status arrived through. This class is
 * the single place that rule lives.
 */
class Euromail_Status_Promoter {

	/**
	 * Event/status types that promote a row forward, ranked low to high. A
	 * later value with a higher rank promotes the status; a lower-ranked
	 * value arriving out of order (e.g. 'delivered' after 'opened') never
	 * demotes it. 'bounced' and 'complained' are handled separately: they
	 * always win, and once set are never overwritten by anything.
	 * 'deferred' is recorded in the events timeline but never changes
	 * status at all.
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
	 * Statuses that, once reached, no later value may change.
	 *
	 * @var string[]
	 */
	const TERMINAL_STATUSES = array( 'bounced', 'complained' );

	/**
	 * Compute a row's next status for an incoming event or API-reported
	 * status, with no demotion: a 'bounced'/'complained' status is
	 * permanent once set; a 'bounced'/'complained' value always wins over
	 * any prior status; a 'deferred' value never changes status; any other
	 * recognized value only promotes status forward along the
	 * sent->delivered->opened->clicked rank, never backward; an
	 * unrecognized value is ignored and leaves the current status alone.
	 *
	 * @param string $current_status Row's current status.
	 * @param string $incoming       Event type from a webhook, or a status string from the API.
	 * @return string
	 */
	public static function promote( $current_status, $incoming ) {
		if ( in_array( $current_status, self::TERMINAL_STATUSES, true ) ) {
			return $current_status;
		}

		if ( in_array( $incoming, self::TERMINAL_STATUSES, true ) ) {
			return $incoming;
		}

		if ( 'deferred' === $incoming ) {
			return $current_status;
		}

		if ( ! isset( self::STATUS_RANK[ $incoming ] ) ) {
			return $current_status;
		}

		$current_rank = isset( self::STATUS_RANK[ $current_status ] ) ? self::STATUS_RANK[ $current_status ] : 0;

		return self::STATUS_RANK[ $incoming ] > $current_rank ? $incoming : $current_status;
	}
}
