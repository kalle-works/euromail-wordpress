<?php
/**
 * Tests for Euromail_Smtp_Backend, without ever opening a real socket.
 *
 * Guarantees under test:
 * - The canonical email is mapped correctly onto PHPMailer: from/name,
 *   to/cc/bcc/reply-to (including "Name <addr>" forms), subject, HTML vs.
 *   plain body, custom headers, and attachments (via path when the file
 *   still exists, else via base64 content).
 * - A successful send returns PHPMailer's own Message-ID.
 * - A PHPMailer failure is thrown back out as Euromail_Retryable_Exception
 *   or Euromail_Permanent_Exception depending on the error text, not left
 *   as a raw PHPMailer\PHPMailer\Exception.
 *
 * @package Euromail
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * A PHPMailer that never touches the network: postSend() is the single
 * method PHPMailer calls to actually push bytes over the wire (regardless
 * of isSMTP()/isMail()/isSendmail()), so overriding it is the standard way
 * to unit test PHPMailer-based code. Everything else — header/MIME
 * building, address validation, Message-ID generation — still runs for
 * real.
 */
class Euromail_Test_Non_Sending_PHPMailer extends PHPMailer {

	/**
	 * @var string|null
	 */
	public $forced_failure_message;

	public function postSend() {
		if ( null !== $this->forced_failure_message ) {
			throw new PHPMailerException( $this->forced_failure_message );
		}

		return true;
	}
}

class Test_Euromail_Smtp_Backend extends WP_UnitTestCase {

	/**
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

		delete_option( 'euromail_smtp_host' );
		delete_option( 'euromail_smtp_port' );
		delete_option( 'euromail_smtp_auth' );

		parent::tear_down();
	}

	private function make_temp_file( $contents ) {
		$path = tempnam( sys_get_temp_dir(), 'euromail-smtp-backend-test-' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		return $path;
	}

	private function base_email( array $overrides = array() ) {
		return array_merge(
			array(
				'from'        => 'sender@example.com',
				'from_name'   => 'Sender Name',
				'to'          => array( 'a@example.com', 'Jane Doe <b@example.com>' ),
				'cc'          => array(),
				'bcc'         => array(),
				'reply_to'    => '',
				'subject'     => 'Hello',
				'text_body'   => 'Body text',
				'headers'     => array(),
				'attachments' => array(),
			),
			$overrides
		);
	}

	/**
	 * Builds a backend whose PHPMailer never touches the network, plus a
	 * holder object that ends up with a `mailer` property pointing at the
	 * PHPMailer instance once send() has actually run — objects are
	 * handle-like in PHP, so mutating $holder->mailer inside the factory
	 * closure is visible to the caller without any reference gymnastics.
	 *
	 * @param string|null $forced_failure_message When set, postSend() throws with this message instead of "succeeding".
	 * @return array{0: Euromail_Smtp_Backend, 1: object}
	 */
	private function make_backend_and_holder( $forced_failure_message = null ) {
		update_option( 'euromail_smtp_host', 'smtp.example.com' );
		update_option( 'euromail_smtp_auth', '0' );

		$holder         = new stdClass();
		$holder->mailer = null;

		$factory = function () use ( $holder, $forced_failure_message ) {
			$mail                          = new Euromail_Test_Non_Sending_PHPMailer( true );
			$mail->forced_failure_message  = $forced_failure_message;
			$holder->mailer                = $mail;
			return $mail;
		};

		return array( new Euromail_Smtp_Backend( $factory ), $holder );
	}

	public function test_maps_from_to_cc_bcc_reply_to_and_subject() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send(
			$this->base_email(
				array(
					'cc'       => array( 'cc@example.com' ),
					'bcc'      => array( 'bcc@example.com' ),
					'reply_to' => 'Reply Person <reply@example.com>',
				)
			),
			'idem-key'
		);

		$captured = $holder->mailer;

		$this->assertSame( 'sender@example.com', $captured->From );
		$this->assertSame( 'Sender Name', $captured->FromName );
		$this->assertSame( 'Hello', $captured->Subject );

		$to = $captured->getToAddresses();
		$this->assertCount( 2, $to );
		$this->assertSame( 'a@example.com', $to[0][0] );
		$this->assertSame( 'b@example.com', $to[1][0] );
		$this->assertSame( 'Jane Doe', $to[1][1] );

		$cc = $captured->getCcAddresses();
		$this->assertSame( 'cc@example.com', $cc[0][0] );

		$bcc = $captured->getBccAddresses();
		$this->assertSame( 'bcc@example.com', $bcc[0][0] );

		$reply_to = $captured->getReplyToAddresses();
		$this->assertSame( 'reply@example.com', $reply_to[0][0] );
		$this->assertSame( 'Reply Person', $reply_to[0][1] );
	}

	public function test_html_body_switches_content_type_to_html() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send( $this->base_email( array( 'html_body' => '<p>Hi</p>', 'text_body' => null ) ), 'idem-key' );

		$captured = $holder->mailer;
		$this->assertSame( 'text/html', $captured->ContentType );
		$this->assertSame( '<p>Hi</p>', $captured->Body );
	}

	public function test_text_body_keeps_content_type_plain() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send( $this->base_email( array( 'text_body' => 'Plain body' ) ), 'idem-key' );

		$captured = $holder->mailer;
		$this->assertSame( 'text/plain', $captured->ContentType );
		$this->assertSame( 'Plain body', $captured->Body );
	}

	public function test_custom_headers_are_added() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send( $this->base_email( array( 'headers' => array( 'X-Custom' => 'custom-value' ) ) ), 'idem-key' );

		$captured = $holder->mailer;

		$found = false;
		foreach ( $captured->getCustomHeaders() as $header ) {
			if ( 'X-Custom' === $header[0] && 'custom-value' === $header[1] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Custom header must be added to the PHPMailer instance.' );
	}

	public function test_attachment_is_added_from_path_when_the_file_still_exists() {
		$path = $this->make_temp_file( 'attachment bytes' );

		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send(
			$this->base_email(
				array(
					'attachments' => array(
						array( 'filename' => 'file.txt', 'content_type' => 'text/plain', 'path' => $path, 'content' => null ),
					),
				)
			),
			'idem-key'
		);

		$attachments = $holder->mailer->getAttachments();
		$this->assertCount( 1, $attachments );
		$this->assertSame( 'file.txt', $attachments[0][2] );
		$this->assertSame( $path, $attachments[0][0], 'Attachment must be read from the path directly, not re-encoded from content.' );
	}

	public function test_attachment_falls_back_to_content_when_path_is_missing() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$backend->send(
			$this->base_email(
				array(
					'attachments' => array(
						array(
							'filename'     => 'file.txt',
							'content_type' => 'text/plain',
							'path'         => '/does/not/exist.txt',
							'content'      => base64_encode( 'attachment bytes' ),
						),
					),
				)
			),
			'idem-key'
		);

		$attachments = $holder->mailer->getAttachments();
		$this->assertCount( 1, $attachments );
		$this->assertSame( 'file.txt', $attachments[0][2] );
	}

	public function test_successful_send_returns_the_phpmailer_message_id() {
		list( $backend, $holder ) = $this->make_backend_and_holder();

		$result = $backend->send( $this->base_email(), 'idem-key' );

		$this->assertNotNull( $result['message_id'] );
		$this->assertSame( trim( $holder->mailer->getLastMessageID(), '<>' ), $result['message_id'] );
	}

	public function test_permanent_smtp_failure_is_thrown_as_permanent_exception() {
		list( $backend, $holder ) = $this->make_backend_and_holder( 'SMTP Error: Could not authenticate.' );

		$this->expectException( Euromail_Permanent_Exception::class );

		$backend->send( $this->base_email(), 'idem-key' );
	}

	public function test_retryable_smtp_failure_is_thrown_as_retryable_exception() {
		list( $backend, $holder ) = $this->make_backend_and_holder( 'SMTP connect() failed. 421 Service not available' );

		$this->expectException( Euromail_Retryable_Exception::class );

		$backend->send( $this->base_email(), 'idem-key' );
	}
}
