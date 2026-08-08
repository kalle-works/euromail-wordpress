<?php
/**
 * Tests for Euromail_Email_Normalizer.
 *
 * Guarantees under test, derived from the spec (not from reading the
 * implementation first):
 * - Headers given as a raw string or as an array parse to the same result.
 * - From / Cc / Bcc / Reply-To headers are extracted correctly.
 * - The wp_mail_content_type filter picks html_body vs. text_body.
 * - Force-From beats a From: header, which beats the wp_mail_from filter.
 * - "to" accepts both a comma-separated string and an array.
 * - Attachments are base64-encoded and their limits are enforced.
 * - euromail_pre_send_email is applied to the final canonical array.
 *
 * @package Euromail
 */

class Test_Euromail_Email_Normalizer extends WP_UnitTestCase {

	/**
	 * Temp files created by a test, cleaned up in tear_down().
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	public function tear_down() {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();

		delete_option( 'euromail_force_from_enabled' );
		delete_option( 'euromail_force_from_email' );
		delete_option( 'euromail_force_from_name' );

		parent::tear_down();
	}

	private function make_temp_file( $contents, $suffix = '.txt' ) {
		$path = tempnam( sys_get_temp_dir(), 'euromail-test-' );
		rename( $path, $path . $suffix );
		$path .= $suffix;

		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->temp_files[] = $path;

		return $path;
	}

	private function base_atts( array $overrides = array() ) {
		return array_merge(
			array(
				'to'          => 'recipient@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body text',
				'headers'     => '',
				'attachments' => array(),
			),
			$overrides
		);
	}

	public function test_headers_as_string_and_as_array_produce_the_same_result() {
		$string_headers = "Cc: cc@example.com\r\nBcc: bcc@example.com\r\nReply-To: reply@example.com";
		$array_headers  = array( 'Cc: cc@example.com', 'Bcc: bcc@example.com', 'Reply-To: reply@example.com' );

		$from_string = Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'headers' => $string_headers ) ) );
		$from_array  = Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'headers' => $array_headers ) ) );

		$this->assertSame( array( 'cc@example.com' ), $from_string['cc'] );
		$this->assertSame( array( 'bcc@example.com' ), $from_string['bcc'] );
		$this->assertSame( 'reply@example.com', $from_string['reply_to'] );

		$this->assertSame( $from_string['cc'], $from_array['cc'] );
		$this->assertSame( $from_string['bcc'], $from_array['bcc'] );
		$this->assertSame( $from_string['reply_to'], $from_array['reply_to'] );
	}

	public function test_from_header_with_display_name_is_parsed() {
		$atts = $this->base_atts( array( 'headers' => 'From: Jane Doe <jane@example.com>' ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'jane@example.com', $email['from'] );
		$this->assertSame( 'Jane Doe', $email['from_name'] );
	}

	public function test_from_header_without_display_name_is_parsed() {
		$atts = $this->base_atts( array( 'headers' => 'From: jane@example.com' ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'jane@example.com', $email['from'] );
	}

	public function test_content_type_html_produces_html_body() {
		$atts = $this->base_atts(
			array(
				'headers' => 'Content-Type: text/html; charset=UTF-8',
				'message' => '<p>Hi</p>',
			)
		);

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( '<p>Hi</p>', $email['html_body'] );
		$this->assertArrayNotHasKey( 'text_body', $email );
	}

	public function test_default_content_type_produces_text_body() {
		$email = Euromail_Email_Normalizer::normalize( $this->base_atts() );

		$this->assertSame( 'Body text', $email['text_body'] );
		$this->assertArrayNotHasKey( 'html_body', $email );
	}

	public function test_force_from_beats_from_header() {
		update_option( 'euromail_force_from_enabled', true );
		update_option( 'euromail_force_from_email', 'force@example.com' );
		update_option( 'euromail_force_from_name', 'Force Name' );

		$atts = $this->base_atts( array( 'headers' => 'From: Header Name <header@example.com>' ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'force@example.com', $email['from'] );
		$this->assertSame( 'Force Name', $email['from_name'] );
	}

	public function test_from_header_beats_default_from_filter_when_force_from_is_disabled() {
		delete_option( 'euromail_force_from_enabled' );

		$atts = $this->base_atts( array( 'headers' => 'From: Header Name <header@example.com>' ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'header@example.com', $email['from'] );
		$this->assertSame( 'Header Name', $email['from_name'] );
	}

	public function test_to_accepts_comma_separated_string_and_keeps_rfc_form() {
		$atts = $this->base_atts( array( 'to' => 'a@example.com, Jane Doe <b@example.com>' ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( array( 'a@example.com', 'Jane Doe <b@example.com>' ), $email['to'] );
	}

	public function test_to_accepts_array() {
		$atts = $this->base_atts( array( 'to' => array( 'a@example.com', 'b@example.com' ) ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $email['to'] );
	}

	public function test_residual_headers_pass_through() {
		$atts = $this->base_atts( array( 'headers' => "X-Custom-Header: custom-value\r\nX-Another: another-value" ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'custom-value', $email['headers']['X-Custom-Header'] );
		$this->assertSame( 'another-value', $email['headers']['X-Another'] );
	}

	public function test_attachment_is_base64_encoded_with_detected_content_type() {
		$path = $this->make_temp_file( 'hello world', '.txt' );

		$atts = $this->base_atts( array( 'attachments' => array( $path ) ) );

		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertCount( 1, $email['attachments'] );
		$this->assertSame( basename( $path ), $email['attachments'][0]['filename'] );
		$this->assertSame( base64_encode( 'hello world' ), $email['attachments'][0]['content'] );
		$this->assertSame( 'text/plain', $email['attachments'][0]['content_type'] );
	}

	public function test_more_than_ten_attachments_throws() {
		$paths = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$paths[] = $this->make_temp_file( 'x', '.txt' );
		}

		$this->expectException( Exception::class );

		Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'attachments' => $paths ) ) );
	}

	public function test_attachment_over_10mb_throws() {
		$path = $this->make_temp_file( str_repeat( 'x', 10 * 1024 * 1024 + 1 ), '.txt' );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( basename( $path ), '/' ) . '/' );

		Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'attachments' => array( $path ) ) ) );
	}

	public function test_attachments_over_25mb_total_throws() {
		$paths = array(
			$this->make_temp_file( str_repeat( 'x', 9 * 1024 * 1024 ), '.txt' ),
			$this->make_temp_file( str_repeat( 'x', 9 * 1024 * 1024 ), '.txt' ),
			$this->make_temp_file( str_repeat( 'x', 9 * 1024 * 1024 ), '.txt' ),
		);

		$this->expectException( Exception::class );

		Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'attachments' => $paths ) ) );
	}

	public function test_blocked_extensions_are_rejected() {
		$blocked = array( 'exe', 'bat', 'cmd', 'scr', 'com', 'msi', 'js', 'vbs', 'ws', 'ps1' );

		foreach ( $blocked as $extension ) {
			$path = $this->make_temp_file( 'x', '.' . $extension );

			try {
				Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'attachments' => array( $path ) ) ) );
				$this->fail( "Expected an exception for blocked extension: $extension" );
			} catch ( Exception $e ) {
				$this->assertStringContainsString( basename( $path ), $e->getMessage() );
			}
		}
	}

	public function test_euromail_pre_send_email_filter_is_applied() {
		add_filter(
			'euromail_pre_send_email',
			function ( $email ) {
				$email['from_name'] = 'Filtered Name';
				return $email;
			}
		);

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts() );

		remove_all_filters( 'euromail_pre_send_email' );

		$this->assertSame( 'Filtered Name', $email['from_name'] );
	}

	// -- Force From defense (empty/invalid stored value is ignored) --

	public function test_normalizer_ignores_force_from_when_stored_email_is_invalid() {
		update_option( 'euromail_force_from_enabled', true );
		update_option( 'euromail_force_from_email', 'not-an-email' );
		update_option( 'euromail_force_from_name', 'Force Name' );

		$atts  = $this->base_atts( array( 'headers' => 'From: Header Name <header@example.com>' ) );
		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( 'header@example.com', $email['from'] );
		$this->assertSame( 'Header Name', $email['from_name'] );
	}

	public function test_normalizer_ignores_force_from_when_stored_email_is_empty() {
		update_option( 'euromail_force_from_enabled', true );
		update_option( 'euromail_force_from_email', '' );

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts() );

		// Falls through to the filtered default (wordpress@sitename), not an empty From.
		$this->assertNotSame( '', $email['from'] );
	}

	// -- wp_mail_from / wp_mail_from_name filter order --

	public function test_wp_mail_from_filter_receives_header_value_and_its_return_wins() {
		$seen = null;
		add_filter(
			'wp_mail_from',
			function ( $email ) use ( &$seen ) {
				$seen = $email;
				return 'filtered@example.com';
			}
		);

		$atts   = $this->base_atts( array( 'headers' => 'From: header@example.com' ) );
		$result = Euromail_Email_Normalizer::normalize( $atts );

		remove_all_filters( 'wp_mail_from' );

		$this->assertSame( 'header@example.com', $seen, 'The filter must see the header value, not the default.' );
		$this->assertSame( 'filtered@example.com', $result['from'], "The filter's return value must win over the header." );
	}

	public function test_wp_mail_from_name_filter_receives_header_name_and_its_return_wins() {
		$seen = null;
		add_filter(
			'wp_mail_from_name',
			function ( $name ) use ( &$seen ) {
				$seen = $name;
				return 'Filtered Name';
			}
		);

		$atts   = $this->base_atts( array( 'headers' => 'From: Header Name <header@example.com>' ) );
		$result = Euromail_Email_Normalizer::normalize( $atts );

		remove_all_filters( 'wp_mail_from_name' );

		$this->assertSame( 'Header Name', $seen );
		$this->assertSame( 'Filtered Name', $result['from_name'] );
	}

	public function test_force_from_beats_wp_mail_from_filter_too() {
		update_option( 'euromail_force_from_enabled', true );
		update_option( 'euromail_force_from_email', 'force@example.com' );

		add_filter(
			'wp_mail_from',
			function () {
				return 'filtered@example.com';
			}
		);

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts() );

		remove_all_filters( 'wp_mail_from' );

		$this->assertSame( 'force@example.com', $email['from'] );
	}

	// -- wp_mail_content_type filter order --

	public function test_wp_mail_content_type_filter_can_add_charset_suffix_and_still_detect_html() {
		add_filter(
			'wp_mail_content_type',
			function () {
				return 'text/html; charset=UTF-8';
			}
		);

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'message' => '<p>Hi</p>' ) ) );

		remove_all_filters( 'wp_mail_content_type' );

		$this->assertSame( '<p>Hi</p>', $email['html_body'] );
		$this->assertArrayNotHasKey( 'text_body', $email );
	}

	public function test_wp_mail_content_type_filter_sees_header_value_and_its_return_wins() {
		$seen = null;
		add_filter(
			'wp_mail_content_type',
			function ( $type ) use ( &$seen ) {
				$seen = $type;
				return 'text/plain'; // Downgrade despite an HTML header.
			}
		);

		$atts  = $this->base_atts( array( 'headers' => 'Content-Type: text/html; charset=UTF-8' ) );
		$email = Euromail_Email_Normalizer::normalize( $atts );

		remove_all_filters( 'wp_mail_content_type' );

		$this->assertSame( 'text/html', $seen, 'The filter must see the header-derived type.' );
		$this->assertSame( 'Body text', $email['text_body'] );
		$this->assertArrayNotHasKey( 'html_body', $email );
	}

	// -- Charset conversion to UTF-8 --

	public function test_charset_conversion_to_utf8_when_wp_mail_charset_is_not_utf8() {
		add_filter(
			'wp_mail_charset',
			function () {
				return 'ISO-8859-1';
			}
		);

		$subject_utf8 = 'Ää Öö testi';
		$subject_iso  = mb_convert_encoding( $subject_utf8, 'ISO-8859-1', 'UTF-8' );

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'subject' => $subject_iso ) ) );

		remove_all_filters( 'wp_mail_charset' );

		$this->assertSame( $subject_utf8, $email['subject'] );
		$this->assertNotFalse( wp_json_encode( $email ), 'The canonical email must always be valid UTF-8 / JSON-encodable.' );
	}

	public function test_charset_conversion_applies_to_recipient_display_names() {
		add_filter(
			'wp_mail_charset',
			function () {
				return 'ISO-8859-1';
			}
		);

		$name_utf8 = 'Jönköping Testaaja';
		$name_iso  = mb_convert_encoding( $name_utf8, 'ISO-8859-1', 'UTF-8' );

		$atts  = $this->base_atts( array( 'to' => $name_iso . ' <recipient@example.com>' ) );
		$email = Euromail_Email_Normalizer::normalize( $atts );

		remove_all_filters( 'wp_mail_charset' );

		$this->assertSame( $name_utf8 . ' <recipient@example.com>', $email['to'][0] );
	}

	public function test_no_conversion_happens_when_charset_is_already_utf8() {
		add_filter(
			'wp_mail_charset',
			function () {
				return 'UTF-8';
			}
		);

		$email = Euromail_Email_Normalizer::normalize( $this->base_atts( array( 'subject' => 'Ää Öö unchanged' ) ) );

		remove_all_filters( 'wp_mail_charset' );

		$this->assertSame( 'Ää Öö unchanged', $email['subject'] );
	}

	// -- Attachment filename keys (WP 6.2 custom-filename contract) --

	public function test_string_key_preserves_custom_filename_and_resolves_content_type_from_key() {
		// A tempnam()-style path with no extension of its own, like the
		// paths WordPress itself generates for uploads.
		$path = tempnam( sys_get_temp_dir(), 'euromail-test-' );
		file_put_contents( $path, '%PDF-1.4 fake pdf content' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		$atts  = $this->base_atts( array( 'attachments' => array( 'Invoice-1042.pdf' => $path ) ) );
		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertCount( 1, $email['attachments'] );
		$this->assertSame( 'Invoice-1042.pdf', $email['attachments'][0]['filename'] );
		$this->assertSame( 'application/pdf', $email['attachments'][0]['content_type'] );
	}

	public function test_numeric_keys_still_fall_back_to_basename() {
		$path = $this->make_temp_file( 'hello world', '.txt' );

		$atts  = $this->base_atts( array( 'attachments' => array( $path ) ) );
		$email = Euromail_Email_Normalizer::normalize( $atts );

		$this->assertSame( basename( $path ), $email['attachments'][0]['filename'] );
	}
}
