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
 * Hooked onto the `pre_wp_mail` filter.
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

		$backends = $this->get_backends();

		if ( empty( $backends ) ) {
			// Configured, but no backend is able to send (e.g. the SDK is
			// not installed yet). Fall back to core wp_mail() rather than
			// silently failing every send.
			return null;
		}

		$idempotency_key = wp_generate_uuid4();

		$log_id = Euromail_Logger::create(
			array(
				'status'          => 'sending',
				'idempotency_key' => $idempotency_key,
				'mail_from'       => $email['from'],
				'mail_to'         => implode( ', ', $email['to'] ),
				'subject'         => $email['subject'],
				'payload'         => wp_json_encode( $email ),
			)
		);

		$last_error = '';

		foreach ( $backends as $name => $backend ) {
			try {
				$result = $backend->send( $email, $idempotency_key );

				Euromail_Logger::update(
					$log_id,
					array(
						'status'     => 'sent',
						'backend'    => $name,
						'message_id' => isset( $result['message_id'] ) ? $result['message_id'] : null,
						'payload'    => Euromail_Settings::get( 'euromail_store_body' ) ? wp_json_encode( $email ) : null,
						'attempts'   => 1,
					)
				);

				return true;
			} catch ( Exception $e ) {
				$last_error = sprintf( '%s: %s %s', $name, $e->getCode(), $e->getMessage() );
			}
		}

		Euromail_Logger::update(
			$log_id,
			array(
				'status'   => 'failed',
				'error'    => $last_error,
				'attempts' => 1,
			)
		);

		do_action( 'wp_mail_failed', new WP_Error( 'euromail_send_failed', $last_error ) );

		return false;
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
	 * Filterable via `euromail_backends` so tests (and, later, the SMTP
	 * fallback) can inject or reorder backends without touching this class.
	 *
	 * @return array<string, object> Backend name => object exposing send( array $email, string $idempotency_key ): array.
	 */
	private function get_backends() {
		$backends = array();

		if ( 'api' === Euromail_Settings::get( 'euromail_backend' ) && class_exists( 'Euromail_Api_Backend' ) ) {
			$backends['api'] = new Euromail_Api_Backend();
		}

		/**
		 * Filters the ordered backend chain used to send an email.
		 *
		 * @param array<string, object> $backends Backend name => backend instance.
		 */
		return apply_filters( 'euromail_backends', $backends );
	}
}
