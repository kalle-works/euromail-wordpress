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
	 * How long a row may sit in 'sending' before it is considered
	 * abandoned by a crashed worker and reclaimed by a later run.
	 */
	const STALE_SENDING_SECONDS = 900; // 15 minutes.

	/**
	 * Process every row that is due for a retry — a 'queued' row whose
	 * next_attempt_at has passed, or a 'sending' row stuck there since
	 * before the stale threshold. Hooked onto the euromail_process_retry_queue
	 * cron event.
	 */
	public static function process() {
		$stale_sending_before = self::stale_sending_threshold();

		foreach ( Euromail_Logger::due_queue_ids( self::BATCH_SIZE, null, $stale_sending_before ) as $id ) {
			self::process_row( $id, $stale_sending_before );
		}
	}

	/**
	 * Attempt a single retry.
	 *
	 * @param int         $id                   Log row ID.
	 * @param string|null $stale_sending_before MySQL datetime; a 'sending' row last touched at or before this may be reclaimed. Defaults to now — safe for a direct call (e.g. an admin resend) outside a process() batch.
	 */
	public static function process_row( $id, $stale_sending_before = null ) {
		if ( ! Euromail_Logger::claim_for_retry( $id, $stale_sending_before ) ) {
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
			$error = __( 'Euromail: could not retry — the stored payload is missing or unreadable.', 'euromail' );

			Euromail_Logger::update(
				$id,
				array(
					'status' => 'failed',
					'error'  => $error,
				)
			);

			do_action( 'wp_mail_failed', new WP_Error( 'euromail_send_failed', $error, array( 'attempts' => (int) $row['attempts'] ) ) );
			return;
		}

		$attempts = (int) $row['attempts'] + 1;
		$mailer   = new Euromail_Mailer();
		$result   = $mailer->attempt_send( $email, $row['idempotency_key'] );

		if ( $result['success'] ) {
			Euromail_Mailer::finalize_log(
				$id,
				'sent',
				$email,
				array(
					'backend'         => $result['backend'],
					'message_id'      => $result['message_id'],
					'api_id'          => $result['api_id'],
					'attempts'        => $attempts,
					'next_attempt_at' => null,
					'error'           => null,
				)
			);

			do_action( 'wp_mail_succeeded', self::reconstruct_atts_from_email( $email ) );
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

		Euromail_Mailer::finalize_log(
			$id,
			'failed',
			$email,
			array(
				'attempts'        => $attempts,
				'error'           => $result['error'],
				'next_attempt_at' => null,
			)
		);

		do_action( 'wp_mail_failed', new WP_Error( 'euromail_send_failed', $result['error'], array( 'attempts' => $attempts ) ) );
	}

	/**
	 * Compute the MySQL datetime for the next retry: the backend's own
	 * Retry-After hint when it gave one, otherwise the fixed backoff table
	 * indexed by how many attempts have been made so far. Public: also
	 * used by Euromail_Mailer to schedule the very first retry, so a
	 * Retry-After hint on the initial attempt is honored the same way.
	 *
	 * @param int      $attempts_made Total attempts made, including this one.
	 * @param int|null $retry_after   Seconds the backend asked us to wait, if any.
	 * @return string
	 */
	public static function next_attempt_at( $attempts_made, $retry_after ) {
		if ( null !== $retry_after && $retry_after > 0 ) {
			$delay = $retry_after;
		} else {
			$index = min( $attempts_made - 1, count( self::BACKOFF_SECONDS ) - 1 );
			$delay = self::BACKOFF_SECONDS[ $index ];
		}

		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * MySQL datetime before which a 'sending' row is considered abandoned.
	 *
	 * @return string
	 */
	private static function stale_sending_threshold() {
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STALE_SENDING_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * Rebuild wp_mail_succeeded's argument shape from the canonical email
	 * actually used for this attempt — type-correct (to/headers as
	 * arrays, a single message string, attachment file paths), not the
	 * log row's own denormalized, comma-joined columns.
	 *
	 * @param array $email Canonical email array.
	 * @return array{to: array, subject: string, message: string, headers: array, attachments: string[]}
	 */
	private static function reconstruct_atts_from_email( array $email ) {
		$message = ! empty( $email['html_body'] ) ? $email['html_body'] : ( isset( $email['text_body'] ) ? $email['text_body'] : '' );

		$attachments = array();

		if ( ! empty( $email['attachments'] ) ) {
			foreach ( $email['attachments'] as $attachment ) {
				if ( ! empty( $attachment['path'] ) ) {
					$attachments[] = $attachment['path'];
				}
			}
		}

		return array(
			'to'          => isset( $email['to'] ) ? $email['to'] : array(),
			'subject'     => isset( $email['subject'] ) ? $email['subject'] : '',
			'message'     => $message,
			'headers'     => isset( $email['headers'] ) ? $email['headers'] : array(),
			'attachments' => $attachments,
		);
	}
}
