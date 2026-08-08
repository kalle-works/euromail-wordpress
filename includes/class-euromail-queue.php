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
 * Hooked onto the `euromail_process_queue` cron event
 * (schedule: euromail_minutely).
 */
class Euromail_Queue {

	/**
	 * Delay, in seconds, before each retry attempt: 1 minute, 5 minutes,
	 * 30 minutes, 2 hours, 12 hours — indexed by how many attempts have
	 * been made so far. Combined with MAX_ATTEMPTS = 5, the last of these
	 * only matters if a Retry-After hint stretches a wait past it; the
	 * 5th attempt itself is the final one.
	 *
	 * @var int[]
	 */
	const BACKOFF_SECONDS = array( 60, 300, 1800, 7200, 43200 );

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
	 * Process every retrying row that is due. Hooked onto the
	 * euromail_process_queue cron event.
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

		try {
			$email = self::rehydrate_attachments( $email );
		} catch ( Euromail_Permanent_Exception $e ) {
			Euromail_Logger::update(
				$id,
				array(
					'status'          => 'failed',
					'attempts'        => $attempts,
					'error'           => $e->getMessage(),
					'next_attempt_at' => null,
				)
			);
			return;
		}

		$mailer = new Euromail_Mailer();
		$result = $mailer->attempt_send( $email, $row['idempotency_key'] );

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
					'status'          => 'retrying',
					'attempts'        => $attempts,
					'error'           => $result['error'],
					'next_attempt_at' => self::next_attempt_at( $attempts, $result['retry_after'] ),
				)
			);
			return;
		}

		Euromail_Logger::update(
			$id,
			array(
				'status'          => 'failed',
				'attempts'        => $attempts,
				'error'           => $result['error'],
				'next_attempt_at' => null,
			)
		);
	}

	/**
	 * Re-read each attachment's content fresh from its source 'path' — the
	 * stored payload never carries base64 content (see
	 * Euromail_Mailer::redact_payload_for_storage()) — since the original
	 * request's temp file may or may not still exist by retry time.
	 *
	 * @param array $email Canonical email array decoded from the stored payload.
	 * @return array Same array, with 'content' populated on every attachment.
	 * @throws Euromail_Permanent_Exception When an attachment's file no longer exists; retrying won't bring it back.
	 */
	private static function rehydrate_attachments( array $email ) {
		if ( empty( $email['attachments'] ) ) {
			return $email;
		}

		foreach ( $email['attachments'] as &$attachment ) {
			$path = isset( $attachment['path'] ) ? $attachment['path'] : '';

			if ( '' === $path || ! file_exists( $path ) ) {
				throw new Euromail_Permanent_Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: could not retry — attachment "%s" no longer exists on disk.', 'euromail' ),
						isset( $attachment['filename'] ) ? $attachment['filename'] : $path
					)
				);
			}

			$attachment['content'] = base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		unset( $attachment );

		return $email;
	}

	/**
	 * Compute the MySQL datetime for the next retry: the larger of the
	 * fixed backoff step for this attempt count and any Retry-After hint
	 * the backend gave — a short Retry-After never shortens our own
	 * minimum backoff, it can only lengthen the wait.
	 *
	 * @param int      $attempts_made Total attempts made, including this one.
	 * @param int|null $retry_after   Seconds the backend asked us to wait, if any.
	 * @return string
	 */
	private static function next_attempt_at( $attempts_made, $retry_after ) {
		$index         = min( $attempts_made - 1, count( self::BACKOFF_SECONDS ) - 1 );
		$backoff_delay = self::BACKOFF_SECONDS[ $index ];

		$delay = ( null !== $retry_after && $retry_after > 0 )
			? max( $retry_after, $backoff_delay )
			: $backoff_delay;

		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}
}
