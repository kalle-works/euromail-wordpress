<?php
/**
 * Turns wp_mail() arguments into the canonical email shape the backends use.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes wp_mail() $atts (to, subject, message, headers, attachments)
 * into a canonical array, modeled on core's own header parsing in
 * wp-includes/pluggable.php so third-party plugins that hook the standard
 * wp_mail_* filters keep working unchanged.
 */
class Euromail_Email_Normalizer {

	/**
	 * Maximum number of attachments per email.
	 */
	const MAX_ATTACHMENTS = 10;

	/**
	 * Maximum size, in bytes, for a single attachment.
	 */
	const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

	/**
	 * Maximum combined size, in bytes, for all attachments on one email.
	 */
	const MAX_TOTAL_ATTACHMENT_BYTES = 25 * 1024 * 1024;

	/**
	 * File extensions that are never allowed as attachments.
	 *
	 * @var array
	 */
	const BLOCKED_EXTENSIONS = array( 'exe', 'bat', 'cmd', 'scr', 'com', 'msi', 'js', 'vbs', 'ws', 'ps1' );

	/**
	 * Normalize wp_mail() arguments.
	 *
	 * @param array $atts wp_mail() arguments: to, subject, message, headers, attachments.
	 * @return array Canonical email array.
	 * @throws Exception When an attachment violates a limit or blocked type.
	 */
	public static function normalize( array $atts ) {
		$to          = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject     = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		$content_type = apply_filters( 'wp_mail_content_type', 'text/plain' );

		// Applied for ecosystem compatibility; the resulting charset is not
		// currently forwarded to the API backend but plugins that hook this
		// filter as a side effect (logging, etc.) still see it fire.
		apply_filters( 'wp_mail_charset', get_bloginfo( 'charset' ) );

		$default_from_email = self::default_from_email();
		$default_from_name  = self::default_from_name();

		$cc             = array();
		$bcc            = array();
		$reply_to       = array();
		$custom_headers = array();
		$from_header    = array(
			'email' => '',
			'name'  => '',
		);

		foreach ( self::parse_header_lines( $headers ) as $header ) {
			if ( false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $content ) = explode( ':', $header, 2 );
			$name    = trim( $name );
			$content = trim( $content );

			switch ( strtolower( $name ) ) {
				case 'from':
					$from_header = self::parse_from_header( $content );
					break;

				case 'content-type':
					if ( false !== strpos( $content, ';' ) ) {
						list( $type ) = explode( ';', $content );
						if ( '' !== trim( $type ) ) {
							$content_type = trim( $type );
						}
					} elseif ( '' !== $content ) {
						$content_type = $content;
					}
					break;

				case 'cc':
					$cc = array_merge( $cc, self::split_addresses( $content ) );
					break;

				case 'bcc':
					$bcc = array_merge( $bcc, self::split_addresses( $content ) );
					break;

				case 'reply-to':
					$reply_to = array_merge( $reply_to, self::split_addresses( $content ) );
					break;

				default:
					if ( '' !== $name ) {
						$custom_headers[ $name ] = $content;
					}
					break;
			}
		}

		if ( Euromail_Settings::get( 'euromail_force_from_enabled' ) ) {
			$from_email = (string) Euromail_Settings::get( 'euromail_force_from_email' );
			$from_name  = (string) Euromail_Settings::get( 'euromail_force_from_name' );
		} elseif ( '' !== $from_header['email'] ) {
			$from_email = $from_header['email'];
			$from_name  = '' !== $from_header['name'] ? $from_header['name'] : $default_from_name;
		} else {
			$from_email = $default_from_email;
			$from_name  = $default_from_name;
		}

		$body_key = ( 'text/html' === strtolower( $content_type ) ) ? 'html_body' : 'text_body';

		$canonical = array(
			'from'        => $from_email,
			'from_name'   => $from_name,
			'to'          => self::split_addresses( $to ),
			'cc'          => $cc,
			'bcc'         => $bcc,
			'reply_to'    => implode( ', ', $reply_to ),
			'subject'     => $subject,
			$body_key     => $message,
			'headers'     => $custom_headers,
			'attachments' => self::normalize_attachments( $attachments ),
		);

		/**
		 * Filters the canonical email array right before it is handed to a
		 * send backend. Documented extension point.
		 *
		 * @param array $canonical Canonical email array.
		 */
		return apply_filters( 'euromail_pre_send_email', $canonical );
	}

	/**
	 * Split a header string into its lines, accepting either a raw header
	 * string (with \r\n or \n line endings) or an already-split array, the
	 * same as core wp_mail().
	 *
	 * @param string|array $headers Raw headers.
	 * @return array
	 */
	private static function parse_header_lines( $headers ) {
		if ( empty( $headers ) ) {
			return array();
		}

		if ( is_array( $headers ) ) {
			return $headers;
		}

		return explode( "\n", str_replace( "\r\n", "\n", $headers ) );
	}

	/**
	 * Parse a `From:` header value into email + display name, the same way
	 * core wp_mail() does.
	 *
	 * @param string $content Header value after "From:".
	 * @return array{email: string, name: string}
	 */
	private static function parse_from_header( $content ) {
		$email = '';
		$name  = '';

		$bracket_pos = strpos( $content, '<' );

		if ( false !== $bracket_pos ) {
			if ( $bracket_pos > 0 ) {
				$name = trim( str_replace( '"', '', substr( $content, 0, $bracket_pos - 1 ) ) );
			}

			$email = trim( str_replace( '>', '', substr( $content, $bracket_pos + 1 ) ) );
		} elseif ( '' !== $content ) {
			$email = $content;
		}

		return array(
			'email' => $email,
			'name'  => $name,
		);
	}

	/**
	 * Split a comma-separated address string (or array) into a list,
	 * preserving RFC "Name <addr>" forms.
	 *
	 * @param string|array $value Raw value.
	 * @return array
	 */
	private static function split_addresses( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', $value ) ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	/**
	 * Resolve the default From email address the same way core wp_mail()
	 * does, applying the wp_mail_from filter.
	 *
	 * @return string
	 */
	private static function default_from_email() {
		$sitename = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		$default = 'wordpress@' . $sitename;

		return apply_filters( 'wp_mail_from', $default );
	}

	/**
	 * Resolve the default From name, applying the wp_mail_from_name filter.
	 *
	 * @return string
	 */
	private static function default_from_name() {
		return apply_filters( 'wp_mail_from_name', 'WordPress' );
	}

	/**
	 * Convert wp_mail() attachment file paths into base64 payloads,
	 * enforcing count/size/type limits client-side.
	 *
	 * @param string|array $attachments File path(s).
	 * @return array
	 * @throws Exception When a limit is exceeded or a blocked extension is used.
	 */
	private static function normalize_attachments( $attachments ) {
		if ( empty( $attachments ) ) {
			return array();
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = preg_split( '/\r\n|\r|\n/', $attachments );
		}

		$attachments = array_values( array_filter( array_map( 'trim', $attachments ) ) );

		if ( count( $attachments ) > self::MAX_ATTACHMENTS ) {
			throw new Exception(
				sprintf(
					/* translators: %d: maximum number of attachments allowed */
					__( 'Euromail: an email cannot have more than %d attachments.', 'euromail' ),
					self::MAX_ATTACHMENTS
				)
			);
		}

		$result      = array();
		$total_bytes = 0;

		foreach ( $attachments as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$filename  = basename( $path );
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachment "%s" has a blocked file type and cannot be sent.', 'euromail' ),
						$filename
					)
				);
			}

			$size = (int) filesize( $path );

			if ( $size > self::MAX_ATTACHMENT_BYTES ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachment "%s" is larger than the 10MB per-file limit.', 'euromail' ),
						$filename
					)
				);
			}

			$total_bytes += $size;

			if ( $total_bytes > self::MAX_TOTAL_ATTACHMENT_BYTES ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachments exceed the 25MB total limit at "%s".', 'euromail' ),
						$filename
					)
				);
			}

			$result[] = array(
				'filename'     => $filename,
				'content_type' => self::detect_content_type( $path, $filename ),
				'content'      => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}

		return $result;
	}

	/**
	 * Detect an attachment's MIME type via wp_check_filetype(), falling
	 * back to fileinfo when WordPress does not recognize the extension.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename File name.
	 * @return string
	 */
	private static function detect_content_type( $path, $filename ) {
		$filetype     = wp_check_filetype( $filename );
		$content_type = $filetype['type'];

		if ( empty( $content_type ) && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( false !== $finfo ) {
				$detected = finfo_file( $finfo, $path );
				finfo_close( $finfo );

				if ( is_string( $detected ) && '' !== $detected ) {
					$content_type = $detected;
				}
			}
		}

		return empty( $content_type ) ? 'application/octet-stream' : $content_type;
	}
}
