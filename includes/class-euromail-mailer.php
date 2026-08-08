<?php
/**
 * Intercepts wp_mail() and routes it through the configured backend chain.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooked onto the `pre_wp_mail` filter. Also used directly by Euromail_Queue
 * to retry a previously-queued send.
 */
class Euromail_Mailer {

	/**
	 * `pre_wp_mail` filter callback.
	 *
	 * @param mixed $null Short-circuit value, always null on the way in.
	 * @param array $atts wp_mail() arguments: to, subject, message, headers, attachments.
	 * @return mixed True on success, false on failure, or null to let core wp_mail() proceed.
	 */
	public function pre_wp_mail( $null, array $atts ) {
		if ( ! Euromail_Settings::is_configured() ) {
			// Never break an unconfigured site: let core wp_mail() proceed.
			return null;
		}

		try {
			$email = Euromail_Email_Normalizer::normalize( $atts );
		} catch ( Exception $e ) {
			$this->log_normalize_failure( $atts, $e );
			do_action( 'wp_mail_failed', new WP_Error( 'euromail_normalize_failed', $e->getMessage() ) );

			return false;
		}

		if ( empty( $this->get_backends() ) ) {
			// Configured, but no backend is able to send (e.g. the SDK is
			// not installed yet, or SMTP is selected but not configured).
			// Fall back to core wp_mail() rather than silently failing.
			return null;
		}

		$idempotency_key = wp_generate_uuid4();

		// Attachment content is never stored in the log: Euromail_Queue
		// re-reads a retry's attachments from their source 'path' instead
		// (see redact_payload_for_storage()) — the original request's temp
		// files may or may not still exist by then, and a missing one is
		// reported as a named, permanent failure rather than silently
		// stored as a multi-megabyte blob.
		$log_id = Euromail_Logger::create(
			array(
				'status'          => 'sending',
				'idempotency_key' => $idempotency_key,
				'mail_from'       => $email['from'],
				'mail_to'         => implode( ', ', $email['to'] ),
				'subject'         => $email['subject'],
				'payload'         => wp_json_encode( self::redact_payload_for_storage( $email ) ),
			)
		);

		$result = $this->attempt_send( $email, $idempotency_key );

		if ( $result['success'] ) {
			$store_body = (bool) Euromail_Settings::get( 'euromail_store_body' );

			$this->update_log(
				$log_id,
				array(
					'status'     => 'sent',
					'backend'    => $result['backend'],
					'message_id' => $result['message_id'],
					'payload'    => $store_body ? wp_json_encode( self::redact_payload_for_storage( $email ) ) : null,
					'attempts'   => 1,
				)
			);

			do_action( 'wp_mail_succeeded', $atts );

			return true;
		}

		if ( $result['retryable'] && Euromail_Queue::MAX_ATTEMPTS > 1 ) {
			$this->update_log(
				$log_id,
				array(
					'status'          => 'retrying',
					'error'           => $result['error'],
					'attempts'        => 1,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + Euromail_Queue::BACKOFF_SECONDS[0] ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				)
			);
		} else {
			$this->update_log(
				$log_id,
				array(
					'status'   => 'failed',
					'error'    => $result['error'],
					'attempts' => 1,
				)
			);
		}

		do_action( 'wp_mail_failed', new WP_Error( 'euromail_send_failed', $result['error'] ) );

		return false;
	}

	/**
	 * Try every backend in the configured chain, in order, for one
	 * canonical email. Shared by the initial pre_wp_mail() attempt and by
	 * Euromail_Queue's retries, so both go through identical backend
	 * selection and error classification.
	 *
	 * @param array  $email           Canonical email array.
	 * @param string $idempotency_key Idempotency key (reused as-is across automatic retries).
	 * @return array{success: bool, backend: string|null, message_id: string|null, retryable: bool, error: string, retry_after: int|null}
	 */
	public function attempt_send( array $email, $idempotency_key ) {
		$backends = $this->get_backends();

		if ( empty( $backends ) ) {
			return array(
				'success'     => false,
				'backend'     => null,
				'message_id'  => null,
				'retryable'   => true,
				'error'       => 'no backend configured',
				'retry_after' => null,
			);
		}

		$last_error       = '';
		$last_retryable   = true;
		$last_retry_after = null;

		foreach ( $backends as $name => $backend ) {
			try {
				$result = $backend->send( $email, $idempotency_key );

				return array(
					'success'     => true,
					'backend'     => $name,
					'message_id'  => isset( $result['message_id'] ) ? $result['message_id'] : null,
					'retryable'   => false,
					'error'       => '',
					'retry_after' => null,
				);
			} catch ( Throwable $e ) {
				// A backend (or the SDK/HTTP/SMTP layer beneath it) may
				// throw anything, including Errors, not just Exceptions.
				// wp_mail() must never fatal because of it.
				$last_error       = sprintf( '%s: %s %s', $name, $e->getCode(), $e->getMessage() );
				$last_retryable   = self::is_retryable_exception( $e );
				$last_retry_after = self::retry_after_from_exception( $e );
			}
		}

		return array(
			'success'     => false,
			'backend'     => null,
			'message_id'  => null,
			'retryable'   => $last_retryable,
			'error'       => $last_error,
			'retry_after' => $last_retry_after,
		);
	}

	/**
	 * Classify a Throwable from a backend as worth retrying or not.
	 *
	 * @param Throwable $e Exception/Error thrown by a backend's send().
	 * @return bool
	 */
	public static function is_retryable_exception( Throwable $e ) {
		if ( $e instanceof Euromail_Retryable_Exception ) {
			return true;
		}

		if ( $e instanceof Euromail_Permanent_Exception ) {
			return false;
		}

		if ( EUROMAIL_SDK_LOADED && $e instanceof EuroMail\Exceptions\EuroMailException ) {
			return $e->isRetryable();
		}

		// Unknown Throwable: default to retryable. A spurious retry costs
		// little (idempotency prevents a duplicate send); silently giving
		// up on a genuinely transient error does not.
		return true;
	}

	/**
	 * Extract a backend-provided "wait this long" hint, when there is one.
	 *
	 * @param Throwable $e Exception/Error thrown by a backend's send().
	 * @return int|null
	 */
	private static function retry_after_from_exception( Throwable $e ) {
		if ( EUROMAIL_SDK_LOADED && $e instanceof EuroMail\Exceptions\EuroMailException ) {
			return $e->getRetryAfter();
		}

		return null;
	}

	/**
	 * Update a log row, skipping gracefully when the initial insert failed
	 * (log_id 0) instead of trying to update a row that doesn't exist.
	 *
	 * @param int   $log_id Row ID returned by Euromail_Logger::create().
	 * @param array $data   Column values to change.
	 */
	private function update_log( $log_id, array $data ) {
		if ( $log_id > 0 ) {
			Euromail_Logger::update( $log_id, $data );
		}
	}

	/**
	 * Strip attachment content (base64) from a canonical email before it is
	 * ever stored. Attachment bytes never touch the database, at any row
	 * status: Euromail_Queue re-reads them from their source 'path' at
	 * retry time instead, and a resend goes through the same path.
	 * filename/content_type/path/size are kept.
	 *
	 * @param array $email Canonical email array.
	 * @return array
	 */
	public static function redact_payload_for_storage( array $email ) {
		if ( ! empty( $email['attachments'] ) ) {
			$email['attachments'] = array_map(
				function ( $attachment ) {
					unset( $attachment['content'] );
					return $attachment;
				},
				$email['attachments']
			);
		}

		return $email;
	}

	/**
	 * Record a log row for an email that failed to normalize, before any
	 * backend was attempted.
	 *
	 * @param array     $atts Raw wp_mail() arguments.
	 * @param Exception $e    The normalizer exception.
	 */
	private function log_normalize_failure( array $atts, Exception $e ) {
		$to = isset( $atts['to'] ) ? $atts['to'] : '';

		Euromail_Logger::create(
			array(
				'status'          => 'failed',
				'idempotency_key' => wp_generate_uuid4(),
				'mail_from'       => '',
				'mail_to'         => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
				'subject'         => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'error'           => $e->getMessage(),
				'attempts'        => 1,
			)
		);
	}

	/**
	 * Build the ordered list of send backends to try, keyed by name.
	 *
	 * The primary backend is whichever `euromail_backend` selects ('api' or
	 * 'smtp'); when `euromail_fallback_enabled` is on and the other backend
	 * is configured, it's appended as a second attempt.
	 *
	 * Filterable via `euromail_backends` so tests — and the Send Test
	 * page's backend-override radio — can inject or replace the chain
	 * without touching this class.
	 *
	 * @return array<string, object> Backend name => object exposing send( array $email, string $idempotency_key ): array.
	 */
	private function get_backends() {
		$backends = array();
		$primary  = Euromail_Settings::get( 'euromail_backend' );
		$fallback = (bool) Euromail_Settings::get( 'euromail_fallback_enabled' );

		$order = ( 'smtp' === $primary ) ? array( 'smtp', 'api' ) : array( 'api', 'smtp' );

		foreach ( $order as $index => $name ) {
			$is_primary = ( 0 === $index );

			if ( ! $is_primary && ! $fallback ) {
				continue;
			}

			if ( 'api' === $name && Euromail_Settings::is_api_configured() && class_exists( 'Euromail_Api_Backend' ) ) {
				$backends['api'] = new Euromail_Api_Backend();
			} elseif ( 'smtp' === $name && Euromail_Settings::is_smtp_configured() && class_exists( 'Euromail_Smtp_Backend' ) ) {
				$backends['smtp'] = new Euromail_Smtp_Backend();
			}
		}

		/**
		 * Filters the ordered backend chain used to send an email.
		 *
		 * @param array<string, object> $backends Backend name => backend instance.
		 */
		return apply_filters( 'euromail_backends', $backends );
	}
}
