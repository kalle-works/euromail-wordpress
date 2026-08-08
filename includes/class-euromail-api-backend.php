<?php
/**
 * Sends a canonical email through the euromail.dev API via the SDK.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only loaded once the euromail/euromail-php SDK is installed.
 */
class Euromail_Api_Backend {

	/**
	 * Lazily built SDK client.
	 *
	 * @var EuroMail\Client|null
	 */
	private $client;

	/**
	 * Send a canonical email through the API.
	 *
	 * @param array  $email           Canonical email array, see Euromail_Email_Normalizer::normalize().
	 * @param string $idempotency_key Fresh idempotency key for this attempt.
	 * @return array{message_id: string|null}
	 */
	public function send( array $email, $idempotency_key ) {
		$sent = $this->get_client()->emails->send( $this->build_params( $email, $idempotency_key ) );

		return array(
			'message_id' => isset( $sent->messageId ) ? $sent->messageId : null,
		);
	}

	/**
	 * Map the canonical email array to euromail.dev API send parameters.
	 *
	 * @param array  $email           Canonical email array.
	 * @param string $idempotency_key Fresh idempotency key for this attempt.
	 * @return array
	 */
	private function build_params( array $email, $idempotency_key ) {
		$params = array(
			'from'             => $this->format_from( $email ),
			'to'               => $email['to'],
			'subject'          => $email['subject'],
			'idempotency_key'  => $idempotency_key,
			'transactional'    => (bool) Euromail_Settings::get( 'euromail_transactional_default' ),
			'tracking'         => (bool) Euromail_Settings::get( 'euromail_tracking_default' ),
			'metadata'         => array(
				'source' => 'wordpress',
				'site'   => home_url(),
			),
		);

		foreach ( array( 'cc', 'bcc', 'headers' ) as $key ) {
			if ( ! empty( $email[ $key ] ) ) {
				$params[ $key ] = $email[ $key ];
			}
		}

		if ( ! empty( $email['attachments'] ) ) {
			// The canonical attachment array also carries the local 'path'
			// and 'size' (for logging); the API only needs filename,
			// content_type and the base64 content, and the local
			// filesystem path must never leave the server.
			$params['attachments'] = array_map(
				function ( $attachment ) {
					return array(
						'filename'     => $attachment['filename'],
						'content_type' => $attachment['content_type'],
						'content'      => $attachment['content'],
					);
				},
				$email['attachments']
			);
		}

		if ( '' !== $email['reply_to'] ) {
			$params['reply_to'] = $email['reply_to'];
		}

		if ( ! empty( $email['html_body'] ) ) {
			$params['html_body'] = $email['html_body'];
		}

		if ( ! empty( $email['text_body'] ) ) {
			$params['text_body'] = $email['text_body'];
		}

		return $params;
	}

	/**
	 * Build a "Name <addr>" From string when a display name is set.
	 *
	 * @param array $email Canonical email array.
	 * @return string
	 */
	private function format_from( array $email ) {
		if ( ! empty( $email['from_name'] ) ) {
			return sprintf( '%s <%s>', $email['from_name'], $email['from'] );
		}

		return $email['from'];
	}

	/**
	 * Lazily build the SDK client.
	 *
	 * @return EuroMail\Client
	 */
	private function get_client() {
		if ( null === $this->client ) {
			$this->client = new EuroMail\Client(
				Euromail_Settings::get( 'euromail_api_key' ),
				array(
					'transport'   => new Euromail_Wp_Transport(),
					'base_url'    => Euromail_Settings::get( 'euromail_api_base_url' ),
					'max_retries' => 0,
				)
			);
		}

		return $this->client;
	}
}
