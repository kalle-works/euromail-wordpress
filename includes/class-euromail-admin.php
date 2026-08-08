<?php
/**
 * Hand-rolled admin pages: Settings and Send Test.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Euromail admin menu and renders its pages.
 */
class Euromail_Admin {

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_euromail_verify_key', array( $this, 'ajax_verify_key' ) );
	}

	/**
	 * Register the top-level menu and its submenus.
	 */
	public function add_menu_pages() {
		add_menu_page(
			__( 'Euromail', 'euromail' ),
			__( 'Euromail', 'euromail' ),
			'manage_options',
			'euromail',
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt'
		);

		add_submenu_page(
			'euromail',
			__( 'Settings', 'euromail' ),
			__( 'Settings', 'euromail' ),
			'manage_options',
			'euromail',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'euromail',
			__( 'Send Test', 'euromail' ),
			__( 'Send Test', 'euromail' ),
			'manage_options',
			'euromail-test',
			array( $this, 'render_send_test_page' )
		);
	}

	/**
	 * Enqueue admin.js only on our own pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'euromail' ) ) {
			return;
		}

		wp_enqueue_script(
			'euromail-admin',
			EUROMAIL_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			EUROMAIL_VERSION,
			true
		);

		wp_localize_script(
			'euromail-admin',
			'euromailAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'euromail_verify_key' ),
				'verifyingText' => __( 'Verifying…', 'euromail' ),
				'verifyText'    => __( 'Verify key', 'euromail' ),
			)
		);
	}

	/**
	 * Render the Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['euromail_settings_submit'] ) ) {
			$this->save_settings();
		}

		$api_key              = Euromail_Settings::get( 'euromail_api_key' );
		$api_key_locked       = defined( 'EUROMAIL_API_KEY' ) && '' !== EUROMAIL_API_KEY;
		$api_base_url         = Euromail_Settings::get( 'euromail_api_base_url' );
		$api_base_url_locked  = defined( 'EUROMAIL_API_BASE_URL' ) && '' !== EUROMAIL_API_BASE_URL;
		$force_from_enabled   = Euromail_Settings::get( 'euromail_force_from_enabled' );
		$force_from_email     = Euromail_Settings::get( 'euromail_force_from_email' );
		$force_from_name      = Euromail_Settings::get( 'euromail_force_from_name' );
		$transactional        = Euromail_Settings::get( 'euromail_transactional_default' );
		$tracking             = Euromail_Settings::get( 'euromail_tracking_default' );
		$fallback_enabled     = Euromail_Settings::get( 'euromail_fallback_enabled' );
		$log_retention_days   = Euromail_Settings::get( 'euromail_log_retention_days' );
		$store_body           = Euromail_Settings::get( 'euromail_store_body' );
		$delete_on_uninstall  = Euromail_Settings::get( 'euromail_delete_data_on_uninstall' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Euromail Settings', 'euromail' ); ?></h1>

			<?php settings_errors( 'euromail_settings' ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'euromail_settings', 'euromail_settings_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="euromail_api_key"><?php esc_html_e( 'API key', 'euromail' ); ?></label></th>
						<td>
							<input type="password"
								id="euromail_api_key"
								name="euromail_api_key"
								value="<?php echo esc_attr( $api_key ); ?>"
								class="regular-text"
								autocomplete="off"
								<?php disabled( $api_key_locked ); ?> />
							<button type="button" class="button" id="euromail-verify-key"><?php esc_html_e( 'Verify key', 'euromail' ); ?></button>
							<span id="euromail-verify-result"></span>
							<?php if ( $api_key_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'euromail' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_api_base_url"><?php esc_html_e( 'API base URL', 'euromail' ); ?></label></th>
						<td>
							<input type="text"
								id="euromail_api_base_url"
								name="euromail_api_base_url"
								value="<?php echo esc_attr( $api_base_url ); ?>"
								class="regular-text"
								<?php disabled( $api_base_url_locked ); ?> />
							<?php if ( $api_base_url_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'euromail' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force From address', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_force_from_enabled" value="1" <?php checked( $force_from_enabled ); ?> />
								<?php esc_html_e( 'Override the From address on every outgoing email', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_force_from_email"><?php esc_html_e( 'Forced From email', 'euromail' ); ?></label></th>
						<td><input type="email" id="euromail_force_from_email" name="euromail_force_from_email" value="<?php echo esc_attr( $force_from_email ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_force_from_name"><?php esc_html_e( 'Forced From name', 'euromail' ); ?></label></th>
						<td><input type="text" id="euromail_force_from_name" name="euromail_force_from_name" value="<?php echo esc_attr( $force_from_name ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Transactional by default', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_transactional_default" value="1" <?php checked( $transactional ); ?> />
								<?php esc_html_e( 'Mark outgoing emails as transactional unless overridden', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Open/click tracking by default', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_tracking_default" value="1" <?php checked( $tracking ); ?> />
								<?php esc_html_e( 'Track opens and clicks unless overridden', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SMTP fallback', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_fallback_enabled" value="1" <?php checked( $fallback_enabled ); ?> />
								<?php esc_html_e( 'Fall back to SMTP when the API is unavailable (SMTP configuration arrives in a later release)', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_log_retention_days"><?php esc_html_e( 'Log retention (days)', 'euromail' ); ?></label></th>
						<td><input type="number" min="1" id="euromail_log_retention_days" name="euromail_log_retention_days" value="<?php echo esc_attr( $log_retention_days ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Store message body', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_store_body" value="1" <?php checked( $store_body ); ?> />
								<?php esc_html_e( 'Keep the message payload in the log after a successful send', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="euromail_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
								<?php esc_html_e( 'Remove the log table and settings when the plugin is uninstalled', 'euromail' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="euromail_settings_submit" class="button-primary" value="<?php esc_attr_e( 'Save changes', 'euromail' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the Settings form submission.
	 */
	private function save_settings() {
		if ( ! isset( $_POST['euromail_settings_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['euromail_settings_nonce'] ) ), 'euromail_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! defined( 'EUROMAIL_API_KEY' ) || '' === EUROMAIL_API_KEY ) {
			update_option(
				'euromail_api_key',
				isset( $_POST['euromail_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_api_key'] ) ) : ''
			);
		}

		if ( ! defined( 'EUROMAIL_API_BASE_URL' ) || '' === EUROMAIL_API_BASE_URL ) {
			update_option(
				'euromail_api_base_url',
				isset( $_POST['euromail_api_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['euromail_api_base_url'] ) ) : ''
			);
		}

		$force_from_enabled = isset( $_POST['euromail_force_from_enabled'] );
		$force_from_email   = isset( $_POST['euromail_force_from_email'] ) ? sanitize_email( wp_unslash( $_POST['euromail_force_from_email'] ) ) : '';

		if ( $force_from_enabled && ! is_email( $force_from_email ) ) {
			// Refuse to enable Force From with an empty/invalid address —
			// the normalizer has its own defense against this, but the
			// setting should never be saved in a broken state to begin with.
			update_option( 'euromail_force_from_enabled', false );
			add_settings_error(
				'euromail_settings',
				'euromail_force_from_invalid',
				__( 'Force From was not enabled: enter a valid email address first.', 'euromail' ),
				'error'
			);
		} else {
			update_option( 'euromail_force_from_enabled', $force_from_enabled );
			update_option( 'euromail_force_from_email', $force_from_email );
		}

		update_option(
			'euromail_force_from_name',
			isset( $_POST['euromail_force_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_force_from_name'] ) ) : ''
		);
		update_option( 'euromail_transactional_default', isset( $_POST['euromail_transactional_default'] ) );
		update_option( 'euromail_tracking_default', isset( $_POST['euromail_tracking_default'] ) );
		update_option( 'euromail_fallback_enabled', isset( $_POST['euromail_fallback_enabled'] ) );
		update_option(
			'euromail_log_retention_days',
			isset( $_POST['euromail_log_retention_days'] ) ? absint( $_POST['euromail_log_retention_days'] ) : 30
		);
		update_option( 'euromail_store_body', isset( $_POST['euromail_store_body'] ) );
		update_option( 'euromail_delete_data_on_uninstall', isset( $_POST['euromail_delete_data_on_uninstall'] ) );

		add_settings_error( 'euromail_settings', 'euromail_settings_saved', __( 'Settings saved.', 'euromail' ), 'updated' );
	}

	/**
	 * AJAX handler for the "Verify key" button.
	 *
	 * Verifies whatever key the browser posted (the field's current value,
	 * whether saved yet or not), falling back to the saved key only when
	 * the field was empty. The posted key is never written to the option —
	 * this is a read-only check.
	 */
	public function ajax_verify_key() {
		check_ajax_referer( 'euromail_verify_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'euromail' ) ), 403 );
		}

		$api_key = self::resolve_verification_api_key( $_POST );

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'No API key configured.', 'euromail' ) ) );
		}

		/**
		 * Filters the SDK client used to verify an API key, letting tests
		 * inject a fake client instead of making a real network request.
		 * Returning a non-null value here skips the plugin's own client
		 * construction entirely.
		 *
		 * @param object|null $client  Default null.
		 * @param string      $api_key The key being verified.
		 */
		$client = apply_filters( 'euromail_verify_key_client', null, $api_key );

		if ( null === $client ) {
			if ( ! EUROMAIL_SDK_LOADED ) {
				wp_send_json_error( array( 'message' => __( 'The Euromail SDK is not installed.', 'euromail' ) ) );
			}

			$client = new EuroMail\Client(
				$api_key,
				array(
					'transport'   => new Euromail_Wp_Transport(),
					'base_url'    => Euromail_Settings::get( 'euromail_api_base_url' ),
					'max_retries' => 0,
				)
			);
		}

		// The try/catch deliberately covers only the SDK call, not the
		// wp_send_json_*() calls that follow: those call wp_die()
		// internally, and in the WP AJAX test harness that throws an
		// Exception subclass to simulate it — a catch(Exception) wrapped
		// around them would swallow that and misreport success as failure.
		try {
			$account = $client->account->get();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
			return;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connection verified.', 'euromail' ),
				'account' => $account,
			)
		);
	}

	/**
	 * Resolve which API key an AJAX verification request should check:
	 * the posted 'api_key' field when non-empty, otherwise the saved key.
	 *
	 * @param array $request Typically $_POST.
	 * @return string
	 */
	public static function resolve_verification_api_key( array $request ) {
		$submitted = isset( $request['api_key'] ) ? sanitize_text_field( wp_unslash( $request['api_key'] ) ) : '';

		return '' !== $submitted ? $submitted : (string) Euromail_Settings::get( 'euromail_api_key' );
	}

	/**
	 * Render the Send Test page.
	 */
	public function render_send_test_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = null;

		if ( isset( $_POST['euromail_send_test_submit'] ) ) {
			$result = $this->send_test_email();
		}

		$current_user = wp_get_current_user();
		$default_to   = $current_user ? $current_user->user_email : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Euromail – Send Test', 'euromail' ); ?></h1>

			<?php if ( null !== $result ) : ?>
				<?php if ( $result['success'] ) : ?>
					<div class="notice notice-success"><p>
						<?php
						printf(
							/* translators: %d: delivery log row ID */
							esc_html__( 'Test email sent. Log entry #%d.', 'euromail' ),
							(int) $result['log_id']
						);
						?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $result['message'] ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'euromail_send_test', 'euromail_send_test_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="euromail_test_to"><?php esc_html_e( 'To', 'euromail' ); ?></label></th>
						<td><input type="email" id="euromail_test_to" name="euromail_test_to" value="<?php echo esc_attr( $default_to ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format', 'euromail' ); ?></th>
						<td>
							<label><input type="radio" name="euromail_test_format" value="html" checked /> <?php esc_html_e( 'HTML', 'euromail' ); ?></label>
							&nbsp;
							<label><input type="radio" name="euromail_test_format" value="plain" /> <?php esc_html_e( 'Plain text', 'euromail' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="euromail_send_test_submit" class="button-primary" value="<?php esc_attr_e( 'Send test email', 'euromail' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Send a test email through wp_mail() and look up the log row it produced.
	 *
	 * @return array{success: bool, message?: string, log_id?: int}
	 */
	private function send_test_email() {
		if ( ! isset( $_POST['euromail_send_test_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['euromail_send_test_nonce'] ) ), 'euromail_send_test' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Security check failed.', 'euromail' ),
			);
		}

		$to = isset( $_POST['euromail_test_to'] ) ? sanitize_email( wp_unslash( $_POST['euromail_test_to'] ) ) : '';

		if ( '' === $to || ! is_email( $to ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a valid email address.', 'euromail' ),
			);
		}

		$format  = isset( $_POST['euromail_test_format'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_test_format'] ) ) : 'html';
		$subject = __( 'Euromail test email', 'euromail' );

		if ( 'plain' === $format ) {
			$headers = 'Content-Type: text/plain; charset=UTF-8';
			$message = __( 'This is a test email sent from the Euromail WordPress plugin.', 'euromail' );
		} else {
			$headers = 'Content-Type: text/html; charset=UTF-8';
			$message = '<p>' . esc_html__( 'This is a test email sent from the Euromail WordPress plugin.', 'euromail' ) . '</p>';
		}

		$sent = wp_mail( $to, $subject, $message, $headers );

		global $wpdb;
		$log_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Euromail_Logger::table_name() . ' WHERE mail_to LIKE %s ORDER BY id DESC LIMIT 1',
				'%' . $wpdb->esc_like( $to ) . '%'
			)
		);

		if ( ! $sent ) {
			$log   = $log_id ? Euromail_Logger::get( $log_id ) : null;
			$error = ( $log && ! empty( $log['error'] ) ) ? $log['error'] : __( 'Failed to send the test email.', 'euromail' );

			return array(
				'success' => false,
				'message' => $error,
			);
		}

		return array(
			'success' => true,
			'log_id'  => $log_id,
		);
	}
}
