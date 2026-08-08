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

		// Raw defaults, matching core wp_mail()'s own initial values. Filters
		// are applied later, to the value actually resolved (header or
		// default) — not to the default alone — so they always see and can
		// override whichever value would otherwise be used.
		$content_type = 'text/plain';

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
			$name                   = trim( $name );
			$content                = trim( $content );

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

		// Resolve From email/name: header value if present, else the raw
		// default — THEN apply the wp_mail_from(_name) filters to whichever
		// one that is, matching core precedence (the filter always runs and
		// its return value always wins, whether the base was a header or
		// the default). Force-From, when valid, overrides the result of
		// that filter chain entirely.
		$base_from_email = '' !== $from_header['email'] ? $from_header['email'] : self::default_from_base_email();
		$base_from_name  = '' !== $from_header['name'] ? $from_header['name'] : self::default_from_base_name();

		$filtered_from_email = apply_filters( 'wp_mail_from', $base_from_email );
		$filtered_from_name  = apply_filters( 'wp_mail_from_name', $base_from_name );

		if ( self::has_valid_force_from() ) {
			$from_email = (string) Euromail_Settings::get( 'euromail_force_from_email' );
			$from_name  = (string) Euromail_Settings::get( 'euromail_force_from_name' );
		} else {
			$from_email = $filtered_from_email;
			$from_name  = $filtered_from_name;
		}

		// Same pattern for content type: the filter sees the header-derived
		// (or default) value and its return wins; only afterwards do we
		// strip any ';charset=...' suffix to decide html vs. text, since a
		// filter callback may itself append one.
		$content_type = apply_filters( 'wp_mail_content_type', $content_type );
		$body_key     = ( 'text/html' === self::strip_charset_suffix( $content_type ) ) ? 'html_body' : 'text_body';

		$charset = apply_filters( 'wp_mail_charset', get_bloginfo( 'charset' ) );

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

		if ( '' !== $charset && 0 !== strcasecmp( $charset, 'UTF-8' ) ) {
			$canonical = self::convert_canonical_to_utf8( $canonical, $charset, $body_key );
		}

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
	 * Raw (unfiltered) default From email address, the same base value core
	 * wp_mail() computes before applying the wp_mail_from filter.
	 *
	 * @return string
	 */
	private static function default_from_base_email() {
		$sitename = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Raw (unfiltered) default From name.
	 *
	 * @return string
	 */
	private static function default_from_base_name() {
		return 'WordPress';
	}

	/**
	 * Whether Force From is enabled AND holds a valid email address. Guards
	 * against an admin having enabled the toggle with an empty/invalid
	 * address (which the settings page itself also refuses to save) — if
	 * that ever happens, Force From is ignored rather than sending from an
	 * empty address.
	 *
	 * @return bool
	 */
	private static function has_valid_force_from() {
		if ( ! Euromail_Settings::get( 'euromail_force_from_enabled' ) ) {
			return false;
		}

		return is_email( (string) Euromail_Settings::get( 'euromail_force_from_email' ) );
	}

	/**
	 * Reduce a Content-Type value to its bare, lowercased mime type,
	 * dropping any ";charset=..." (or other parameter) suffix.
	 *
	 * @param string $content_type Raw content type, e.g. "text/html; charset=UTF-8".
	 * @return string
	 */
	private static function strip_charset_suffix( $content_type ) {
		$parts = explode( ';', $content_type, 2 );

		return strtolower( trim( $parts[0] ) );
	}

	/**
	 * Convert every user-supplied text field in the canonical email to
	 * UTF-8, so the API payload is always valid UTF-8 regardless of the
	 * site's wp_mail_charset.
	 *
	 * @param array  $canonical Canonical email array.
	 * @param string $charset   Source charset, e.g. "ISO-8859-1".
	 * @param string $body_key  Either 'html_body' or 'text_body'.
	 * @return array
	 */
	private static function convert_canonical_to_utf8( array $canonical, $charset, $body_key ) {
		$canonical['subject']   = self::convert_to_utf8( $canonical['subject'], $charset );
		$canonical['from_name'] = self::convert_to_utf8( $canonical['from_name'], $charset );
		$canonical[ $body_key ] = self::convert_to_utf8( $canonical[ $body_key ], $charset );

		foreach ( array( 'to', 'cc', 'bcc' ) as $key ) {
			$canonical[ $key ] = array_map(
				function ( $address ) use ( $charset ) {
					return self::convert_address_display_name_to_utf8( $address, $charset );
				},
				$canonical[ $key ]
			);
		}

		$canonical['reply_to'] = self::convert_address_display_name_to_utf8( $canonical['reply_to'], $charset );

		return $canonical;
	}

	/**
	 * Convert only the display-name portion of a "Name <addr>" string to
	 * UTF-8, leaving the address itself untouched.
	 *
	 * @param string $address Address, possibly in "Name <addr>" form.
	 * @param string $charset Source charset.
	 * @return string
	 */
	private static function convert_address_display_name_to_utf8( $address, $charset ) {
		if ( '' === $address ) {
			return $address;
		}

		$bracket_pos = strpos( $address, '<' );

		if ( false === $bracket_pos || 0 === $bracket_pos ) {
			return $address;
		}

		$name = rtrim( substr( $address, 0, $bracket_pos ) );
		$rest = substr( $address, $bracket_pos );

		return self::convert_to_utf8( $name, $charset ) . ' ' . $rest;
	}

	/**
	 * Convert a single string to UTF-8 from the given source charset, via
	 * mb_convert_encoding() with an iconv//TRANSLIT fallback. Passes the
	 * value through unchanged if neither extension is available.
	 *
	 * @param string $value   Source string.
	 * @param string $charset Source charset.
	 * @return string
	 */
	private static function convert_to_utf8( $value, $charset ) {
		if ( '' === $value ) {
			return $value;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $value, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_string( $converted ) && '' !== $converted ) {
				return $converted;
			}
		}

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $charset, 'UTF-8//TRANSLIT', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_string( $converted ) ) {
				return $converted;
			}
		}

		return $value;
	}

	/**
	 * Convert wp_mail() attachment file paths into base64 payloads,
	 * enforcing count/size/type limits client-side.
	 *
	 * String array keys are treated as the WP 6.2+ custom-filename contract
	 * (`['Invoice.pdf' => $tmp_path]`); numeric keys fall back to
	 * basename($path).
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

		$paths = array();

		foreach ( $attachments as $key => $path ) {
			$path = trim( (string) $path );

			if ( '' === $path ) {
				continue;
			}

			$paths[ $key ] = $path;
		}

		if ( count( $paths ) > self::MAX_ATTACHMENTS ) {
			throw new Exception(
				sprintf(
					/* translators: %d: maximum number of attachments allowed */
					__( 'Euromail: an email cannot have more than %d attachments.', 'euromail' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					self::MAX_ATTACHMENTS // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				)
			);
		}

		$result      = array();
		$total_bytes = 0;

		foreach ( $paths as $key => $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$filename  = is_string( $key ) ? $key : basename( $path );
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

			if ( '' === $extension ) {
				// The display filename (a custom string key) had no
				// extension of its own; fall back to the source path's.
				$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			}

			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachment "%s" has a blocked file type and cannot be sent.', 'euromail' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
						$filename // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					)
				);
			}

			$size = (int) filesize( $path );

			if ( $size > self::MAX_ATTACHMENT_BYTES ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachment "%s" is larger than the 10MB per-file limit.', 'euromail' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
						$filename // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					)
				);
			}

			$total_bytes += $size;

			if ( $total_bytes > self::MAX_TOTAL_ATTACHMENT_BYTES ) {
				throw new Exception(
					sprintf(
						/* translators: %s: attachment file name */
						__( 'Euromail: attachments exceed the 25MB total limit at "%s".', 'euromail' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
						$filename // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					)
				);
			}

			$result[] = array(
				'filename'     => $filename,
				'content_type' => self::detect_content_type( $path, $filename ),
				'content'      => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'path'         => $path,
				'size'         => $size,
			);
		}

		return $result;
	}

	/**
	 * Detect an attachment's MIME type via wp_check_filetype(), falling
	 * back to fileinfo when WordPress does not recognize the extension.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename Display file name (may differ from the path's own basename).
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
