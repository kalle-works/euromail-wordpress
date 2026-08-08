<?php
/**
 * Sends a canonical email via SMTP, using WordPress's own bundled PHPMailer.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * No Composer dependency: PHPMailer ships with WordPress core, so this
 * backend is always available regardless of whether the euromail-php SDK
 * is installed.
 */
class Euromail_Smtp_Backend {

	/**
	 * Builds the PHPMailer instance used by send(). Defaults to a real one;
	 * tests can inject a factory returning a PHPMailer subclass whose
	 * postSend() is overridden to skip the network, so the canonical-email
	 * mapping and error classification are unit-testable without opening
	 * real sockets.
	 *
	 * @var callable|null
	 */
	private $mailer_factory;

	/**
	 * @param callable|null $mailer_factory Optional `function(): PHPMailer` override, for tests.
	 */
	public function __construct( $mailer_factory = null ) {
		$this->mailer_factory = $mailer_factory;
	}

	/**
	 * Send a canonical email via SMTP.
	 *
	 * @param array  $email           Canonical email array, see Euromail_Email_Normalizer::normalize().
	 * @param string $idempotency_key Unused by SMTP (no server-side dedup); kept for interface parity with Euromail_Api_Backend.
	 * @return array{message_id: string|null}
	 * @throws Euromail_Smtp_Exception On any SMTP/transport failure, classified retryable or not.
	 */
	public function send( array $email, $idempotency_key ) {
		$mail = null !== $this->mailer_factory ? call_user_func( $this->mailer_factory ) : new PHPMailer( true );

		try {
			$this->configure_transport( $mail );
			$this->configure_message( $mail, $email );

			/**
			 * Fires right before the SMTP send, with a reference to the
			 * PHPMailer instance — same hook and timing as core wp_mail(),
			 * for compatibility with plugins that already use it.
			 *
			 * @param PHPMailer $mail PHPMailer instance.
			 */
			do_action_ref_array( 'phpmailer_init', array( &$mail ) );

			$mail->send();
		} catch ( PHPMailerException $e ) {
			$message = '' !== $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();

			throw new Euromail_Smtp_Exception( $message, self::is_retryable_message( $message ) );
		}

		return array(
			'message_id' => $this->extract_message_id( $mail ),
		);
	}

	/**
	 * Configure host/port/auth/encryption from settings.
	 *
	 * @param PHPMailer $mail PHPMailer instance.
	 */
	private function configure_transport( PHPMailer $mail ) {
		$mail->isSMTP();
		$mail->Host     = Euromail_Settings::get( 'euromail_smtp_host' );
		$mail->Port     = (int) Euromail_Settings::get( 'euromail_smtp_port' );
		$mail->SMTPAuth = (bool) Euromail_Settings::get( 'euromail_smtp_auth' );
		$mail->Timeout  = 15;

		if ( $mail->SMTPAuth ) {
			$mail->Username = Euromail_Settings::get( 'euromail_smtp_username' );
			$mail->Password = Euromail_Settings::get( 'euromail_smtp_password' );
		}

		$encryption = Euromail_Settings::get( 'euromail_smtp_encryption' );

		if ( 'ssl' === $encryption ) {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		} elseif ( 'none' === $encryption ) {
			$mail->SMTPSecure   = '';
			$mail->SMTPAutoTLS = false;
		} else {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		}

		$mail->CharSet = PHPMailer::CHARSET_UTF8;
	}

	/**
	 * Map the canonical email array onto the PHPMailer instance.
	 *
	 * @param PHPMailer $mail  PHPMailer instance.
	 * @param array     $email Canonical email array.
	 */
	private function configure_message( PHPMailer $mail, array $email ) {
		$mail->setFrom( $email['from'], (string) $email['from_name'] );

		$this->add_recipients( $mail, 'addAddress', $email['to'] );
		$this->add_recipients( $mail, 'addCC', $email['cc'] );
		$this->add_recipients( $mail, 'addBCC', $email['bcc'] );

		if ( '' !== $email['reply_to'] ) {
			$this->add_recipients( $mail, 'addReplyTo', array( $email['reply_to'] ) );
		}

		$mail->Subject = $email['subject'];

		if ( ! empty( $email['html_body'] ) ) {
			$mail->isHTML( true );
			$mail->Body = $email['html_body'];
		} else {
			$mail->isHTML( false );
			$mail->Body = isset( $email['text_body'] ) ? $email['text_body'] : '';
		}

		foreach ( $email['headers'] as $name => $value ) {
			$mail->addCustomHeader( $name, $value );
		}

		foreach ( $email['attachments'] as $attachment ) {
			$mail->addStringAttachment(
				base64_decode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$attachment['filename'],
				PHPMailer::ENCODING_BASE64,
				$attachment['content_type']
			);
		}
	}

	/**
	 * Add one or more "Name <addr>" or comma-separated address strings to a
	 * PHPMailer recipient list via the given method (addAddress/addCC/etc.).
	 *
	 * @param PHPMailer $mail      PHPMailer instance.
	 * @param string    $method    Method name to call for each parsed address.
	 * @param string[]  $addresses Address strings.
	 */
	private function add_recipients( PHPMailer $mail, $method, array $addresses ) {
		foreach ( $addresses as $address ) {
			foreach ( PHPMailer::parseAddresses( $address, null, PHPMailer::CHARSET_UTF8 ) as $parsed ) {
				$mail->$method( $parsed['address'], $parsed['name'] );
			}
		}
	}

	/**
	 * @param PHPMailer $mail PHPMailer instance, after a successful send().
	 * @return string|null
	 */
	private function extract_message_id( PHPMailer $mail ) {
		if ( ! method_exists( $mail, 'getLastMessageID' ) ) {
			return null;
		}

		$message_id = trim( $mail->getLastMessageID(), '<>' );

		return '' !== $message_id ? $message_id : null;
	}

	/**
	 * Heuristically classify an SMTP error message as retryable (a
	 * transient 4xx / connection issue) or permanent (a 5xx rejection or an
	 * invalid address). PHPMailer doesn't expose a structured error code,
	 * only free-text ErrorInfo, so this is pattern-based and defaults to
	 * retryable when unsure — a spurious retry is cheap, silently dropping
	 * a transient failure is not.
	 *
	 * Deliberately does NOT hardcode "could not authenticate" as permanent:
	 * an auth failure is classified from its own numeric SMTP reply code
	 * exactly like any other error — a 454/421-style 4xx ("temporary
	 * authentication failure") is retryable, a 535-style 5xx ("authentication
	 * failed") is permanent, and one with no parsable code at all (a local
	 * PHPMailer-generated message, not a server reply) defaults retryable:
	 * a genuinely bad password just exhausts the retry budget and ends up
	 * failed anyway, while a transient outage recovers on its own.
	 *
	 * @param string $message SMTP/PHPMailer error text.
	 * @return bool
	 */
	private static function is_retryable_message( $message ) {
		$permanent_patterns = array( 'invalid address', 'recipient not accepted', 'mailbox unavailable' );

		foreach ( $permanent_patterns as $pattern ) {
			if ( false !== stripos( $message, $pattern ) ) {
				return false;
			}
		}

		if ( preg_match( '/\b5\d{2}\b/', $message ) ) {
			return false;
		}

		if ( preg_match( '/\b4\d{2}\b/', $message ) ) {
			return true;
		}

		return true;
	}
}
