<?php
/**
 * Retries queued sends on a cron schedule.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooked onto the `euromail_process_retry_queue` cron event
 * (schedule: euromail_minutely).
 */
class Euromail_Queue {

	/**
	 * Delay, in seconds, before each retry attempt after the first (failed)
	 * one: 1 minute, 15 minutes, 2 hours, 12 hours. Combined with
	 * MAX_ATTEMPTS = 5, that's up to 4 retries spanning 1 minute to 12 hours.
	 *
	 * @var int[]
	 */
	const BACKOFF_SECONDS = array( 60, 900, 7200, 43200 );

	/**
	 * Total attempts (the original send plus retries) before a retryable
	 * failure is given up on and marked permanently failed.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Maximum number of due rows processed per cron run.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Process every queued row that is due for a retry. Hooked onto the
	 * euromail_process_retry_queue cron event.
	 */
	public static function process() {
		foreach ( Euromail_Logger::due_queue_ids( self::BATCH_SIZE ) as $id ) {
			self::process_row( $id );
		}
	}

	/**
	 * Attempt a single retry.
	 *
	 * @param int $id Log row ID.
	 */
	public static function process_row( $id ) {
		if ( ! Euromail_Logger::claim_for_retry( $id ) ) {
			// Another process already claimed this row (or it moved on
			// before we got to it) — nothing to do.
			return;
		}

		$row = Euromail_Logger::get( $id );

		if ( ! $row ) {
			return;
		}

		$email = ! empty( $row['payload'] ) ? json_decode( $row['payload'], true ) : null;

		if ( ! is_array( $email ) ) {
			Euromail_Logger::update(
				$id,
				array(
					'status' => 'failed',
					'error'  => __( 'Euromail: could not retry — the stored payload is missing or unreadable.', 'euromail' ),
				)
			);
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		$mailer   = new Euromail_Mailer();
		$result   = $mailer->attempt_send( $email, $row['idempotency_key'] );

		if ( $result['success'] ) {
			$store_body = (bool) Euromail_Settings::get( 'euromail_store_body' );

			Euromail_Logger::update(
				$id,
				array(
					'status'          => 'sent',
					'backend'         => $result['backend'],
					'message_id'      => $result['message_id'],
					'attempts'        => $attempts,
					'next_attempt_at' => null,
					'error'           => null,
					'payload'         => $store_body ? wp_json_encode( Euromail_Mailer::redact_payload_for_storage( $email ) ) : null,
				)
			);

			do_action(
				'wp_mail_succeeded',
				array(
					'to'          => $row['mail_to'],
					'subject'     => $row['subject'],
					'message'     => '',
					'headers'     => '',
					'attachments' => array(),
				)
			);
			return;
		}

		if ( $result['retryable'] && $attempts < self::MAX_ATTEMPTS ) {
			Euromail_Logger::update(
				$id,
				array(
					'status'          => 'queued',
					'attempts'        => $attempts,
					'error'           => $result['error'],
					'next_attempt_at' => self::next_attempt_at( $attempts, $result['retry_after'] ),
				)
			);
			return;
		}

		$store_body = (bool) Euromail_Settings::get( 'euromail_store_body' );

		Euromail_Logger::update(
			$id,
			array(
				'status'          => 'failed',
				'attempts'        => $attempts,
				'error'           => $result['error'],
				'next_attempt_at' => null,
				'payload'         => $store_body ? wp_json_encode( Euromail_Mailer::redact_payload_for_storage( $email ) ) : null,
			)
		);
	}

	/**
	 * Compute the MySQL datetime for the next retry: the backend's own
	 * Retry-After hint when it gave one, otherwise the fixed backoff table
	 * indexed by how many attempts have been made so far.
	 *
	 * @param int      $attempts_made Total attempts made, including this one.
	 * @param int|null $retry_after   Seconds the backend asked us to wait, if any.
	 * @return string
	 */
	private static function next_attempt_at( $attempts_made, $retry_after ) {
		if ( null !== $retry_after && $retry_after > 0 ) {
			$delay = $retry_after;
		} else {
			$index = min( $attempts_made - 1, count( self::BACKOFF_SECONDS ) - 1 );
			$delay = self::BACKOFF_SECONDS[ $index ];
		}

		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}
}
