<?php
/**
 * Adapts EuroMail\Http\TransportInterface to wp_remote_request().
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only loaded once the euromail/euromail-php SDK is installed, since it
 * implements one of the SDK's interfaces.
 */
class Euromail_Wp_Transport implements EuroMail\Http\TransportInterface {

	/**
	 * Send an SDK request through WordPress's own HTTP API.
	 *
	 * @param EuroMail\Http\Request $request SDK request object.
	 * @return EuroMail\Http\Response
	 * @throws EuroMail\Exceptions\TransportException On a WP_Error (network failure).
	 */
	public function send( EuroMail\Http\Request $request ): EuroMail\Http\Response {
		$response = wp_remote_request(
			$request->url,
			array(
				'method'  => $request->method,
				'headers' => $request->headers,
				'body'    => $request->body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new EuroMail\Exceptions\TransportException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception message, never echoed as page output.
		}

		$headers = array();

		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = $value;
		}

		return new EuroMail\Http\Response(
			wp_remote_retrieve_response_code( $response ),
			$headers,
			wp_remote_retrieve_body( $response )
		);
	}
}
