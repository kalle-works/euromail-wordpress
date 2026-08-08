<?php
/**
 * "Euromail can send email" Site Health test.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers an async Site Health test: good when the plugin is configured
 * and (API backend) the API is reachable with the stored key, or (SMTP
 * backend) the SMTP configuration is complete.
 */
class Euromail_Site_Health {

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
		add_action( 'wp_ajax_health-check-euromail_can_send', array( $this, 'ajax_run_test' ) );
	}

	/**
	 * Register the async test with Site Health.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_test( array $tests ) {
		$tests['async']['euromail_can_send'] = array(
			'label' => __( 'Euromail can send email', 'euromail' ),
			'test'  => 'euromail_can_send',
		);

		return $tests;
	}

	/**
	 * AJAX handler Site Health's JS calls for the async test.
	 */
	public function ajax_run_test() {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->run_test() );
	}

	/**
	 * Run the test and build its Site Health result shape.
	 *
	 * @return array
	 */
	public function run_test() {
		$result = array(
			'label'       => __( 'Euromail can send email', 'euromail' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Email', 'euromail' ),
				'color' => 'blue',
			),
			'description' => sprintf( '<p>%s</p>', esc_html__( 'Euromail is configured and able to send email.', 'euromail' ) ),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=euromail' ) ),
				esc_html__( 'Open Euromail settings', 'euromail' )
			),
			'test'        => 'euromail_can_send',
		);

		if ( ! Euromail_Settings::is_configured() ) {
			return $this->fail(
				$result,
				'recommended',
				__( 'Euromail is not configured', 'euromail' ),
				__( 'wp_mail() is not yet routed through Euromail. Add an API key or SMTP settings to start sending through it.', 'euromail' )
			);
		}

		$backend = Euromail_Settings::get( 'euromail_backend' );

		if ( 'smtp' === $backend ) {
			if ( ! Euromail_Settings::is_smtp_configured() ) {
				return $this->fail(
					$result,
					'critical',
					__( 'Euromail SMTP settings are incomplete', 'euromail' ),
					__( 'The SMTP backend is selected, but the host, username, or password is missing.', 'euromail' )
				);
			}

			return $result;
		}

		if ( ! Euromail_Settings::is_api_configured() ) {
			return $this->fail(
				$result,
				'critical',
				__( 'Euromail API key is missing', 'euromail' ),
				__( 'The API backend is selected, but no API key is configured.', 'euromail' )
			);
		}

		if ( ! EUROMAIL_SDK_LOADED ) {
			return $this->fail(
				$result,
				'critical',
				__( 'The Euromail SDK is not installed', 'euromail' ),
				__( 'The API backend is selected, but the euromail/euromail-php library is missing.', 'euromail' )
			);
		}

		/**
		 * Filters the SDK client used for the Site Health check, letting
		 * tests inject a fake client instead of making a real network
		 * request.
		 *
		 * @param object|null $client Default null.
		 */
		$client = apply_filters( 'euromail_site_health_client', null );

		if ( null === $client ) {
			$client = new EuroMail\Client(
				Euromail_Settings::get( 'euromail_api_key' ),
				array(
					'transport'   => new Euromail_Wp_Transport(),
					'base_url'    => Euromail_Settings::get( 'euromail_api_base_url' ),
					'max_retries' => 0,
				)
			);
		}

		try {
			$client->account->get();
		} catch ( Throwable $e ) {
			return $this->fail(
				$result,
				'critical',
				__( 'Euromail cannot reach the API', 'euromail' ),
				$e->getMessage()
			);
		}

		return $result;
	}

	/**
	 * Overwrite a passing result with a failing one.
	 *
	 * @param array  $result      Base result array.
	 * @param string $status      'recommended' or 'critical'.
	 * @param string $label       Short label.
	 * @param string $description Actionable explanation.
	 * @return array
	 */
	private function fail( array $result, $status, $label, $description ) {
		$result['status']      = $status;
		$result['label']       = $label;
		$result['description'] = sprintf( '<p>%s</p>', esc_html( $description ) );

		return $result;
	}
}
