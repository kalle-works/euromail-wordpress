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
	 * Filename of the attachment that blocked the most recent resend, set
	 * by resend_log_row() right before it returns the
	 * 'resend_missing_attachment' notice key. A side channel so
	 * maybe_handle_log_row_action() can carry the specific filename into
	 * the redirect's query string, while process_log_row_action() keeps
	 * its existing, tested contract of returning a plain notice-key
	 * string.
	 *
	 * @var string|null
	 */
	private $last_resend_missing_attachment;

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

		add_submenu_page(
			'euromail',
			__( 'Log', 'euromail' ),
			__( 'Log', 'euromail' ),
			'manage_options',
			'euromail-log',
			array( $this, 'render_log_page' )
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
				'relayPreset'   => array(
					'host'       => 'smtp.euromail.dev',
					'port'       => 587,
					'encryption' => 'tls',
					'username'   => 'apikey',
				),
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
		$backend              = Euromail_Settings::get( 'euromail_backend' );
		$smtp_host            = Euromail_Settings::get( 'euromail_smtp_host' );
		$smtp_port            = Euromail_Settings::get( 'euromail_smtp_port' );
		$smtp_encryption      = Euromail_Settings::get( 'euromail_smtp_encryption' );
		$smtp_auth            = Euromail_Settings::get( 'euromail_smtp_auth' );
		$smtp_username        = Euromail_Settings::get( 'euromail_smtp_username' );
		$smtp_password        = Euromail_Settings::get( 'euromail_smtp_password' );
		$smtp_password_locked = defined( 'EUROMAIL_SMTP_PASSWORD' ) && '' !== EUROMAIL_SMTP_PASSWORD;

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
								<input type="checkbox" id="euromail_force_from_enabled" name="euromail_force_from_enabled" value="1" <?php checked( $force_from_enabled ); ?> />
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
								<input type="checkbox" id="euromail_transactional_default" name="euromail_transactional_default" value="1" <?php checked( $transactional ); ?> />
								<?php esc_html_e( 'Mark outgoing emails as transactional unless overridden', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Open/click tracking by default', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="euromail_tracking_default" name="euromail_tracking_default" value="1" <?php checked( $tracking ); ?> />
								<?php esc_html_e( 'Track opens and clicks unless overridden', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_backend"><?php esc_html_e( 'Sending backend', 'euromail' ); ?></label></th>
						<td>
							<select id="euromail_backend" name="euromail_backend">
								<option value="api" <?php selected( $backend, 'api' ); ?>><?php esc_html_e( 'Euromail API', 'euromail' ); ?></option>
								<option value="smtp" <?php selected( $backend, 'smtp' ); ?>><?php esc_html_e( 'SMTP', 'euromail' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Fallback', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="euromail_fallback_enabled" name="euromail_fallback_enabled" value="1" <?php checked( $fallback_enabled ); ?> />
								<?php esc_html_e( 'If the primary backend fails, automatically try the other one', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SMTP settings', 'euromail' ); ?></th>
						<td>
							<button type="button" class="button" id="euromail-smtp-relay-preset"><?php esc_html_e( 'Use Euromail SMTP relay', 'euromail' ); ?></button>
							<p class="description"><?php esc_html_e( 'Fills in the host, port and encryption for euromail.dev’s own SMTP relay, and copies your API key into the password field below.', 'euromail' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_smtp_host"><?php esc_html_e( 'SMTP host', 'euromail' ); ?></label></th>
						<td><input type="text" id="euromail_smtp_host" name="euromail_smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_smtp_port"><?php esc_html_e( 'SMTP port', 'euromail' ); ?></label></th>
						<td><input type="number" min="1" max="65535" id="euromail_smtp_port" name="euromail_smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_smtp_encryption"><?php esc_html_e( 'SMTP encryption', 'euromail' ); ?></label></th>
						<td>
							<select id="euromail_smtp_encryption" name="euromail_smtp_encryption">
								<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>><?php esc_html_e( 'STARTTLS', 'euromail' ); ?></option>
								<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS', 'euromail' ); ?></option>
								<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'euromail' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SMTP authentication', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="euromail_smtp_auth" name="euromail_smtp_auth" value="1" <?php checked( $smtp_auth ); ?> />
								<?php esc_html_e( 'This server requires a username and password', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_smtp_username"><?php esc_html_e( 'SMTP username', 'euromail' ); ?></label></th>
						<td><input type="text" id="euromail_smtp_username" name="euromail_smtp_username" value="<?php echo esc_attr( $smtp_username ); ?>" class="regular-text" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="euromail_smtp_password"><?php esc_html_e( 'SMTP password', 'euromail' ); ?></label></th>
						<td>
							<input type="password"
								id="euromail_smtp_password"
								name="euromail_smtp_password"
								value="<?php echo esc_attr( $smtp_password ); ?>"
								class="regular-text"
								autocomplete="off"
								<?php disabled( $smtp_password_locked ); ?> />
							<?php if ( $smtp_password_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Defined in wp-config.php.', 'euromail' ); ?></p>
							<?php endif; ?>
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
								<input type="checkbox" id="euromail_store_body" name="euromail_store_body" value="1" <?php checked( $store_body ); ?> />
								<?php esc_html_e( 'Keep the message body in the log once a send finishes, success or failure; also enables resending failed emails from the log', 'euromail' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'euromail' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="euromail_delete_data_on_uninstall" name="euromail_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
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
			update_option( 'euromail_force_from_enabled', '0' );
			add_settings_error(
				'euromail_settings',
				'euromail_force_from_invalid',
				__( 'Force From was not enabled: enter a valid email address first.', 'euromail' ),
				'error'
			);
		} else {
			update_option( 'euromail_force_from_enabled', self::bool_option( $force_from_enabled ) );
			update_option( 'euromail_force_from_email', $force_from_email );
		}

		update_option(
			'euromail_force_from_name',
			isset( $_POST['euromail_force_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_force_from_name'] ) ) : ''
		);
		update_option( 'euromail_transactional_default', self::bool_option( isset( $_POST['euromail_transactional_default'] ) ) );
		update_option( 'euromail_tracking_default', self::bool_option( isset( $_POST['euromail_tracking_default'] ) ) );
		update_option( 'euromail_fallback_enabled', self::bool_option( isset( $_POST['euromail_fallback_enabled'] ) ) );

		$backend = isset( $_POST['euromail_backend'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_backend'] ) ) : 'api';
		update_option( 'euromail_backend', in_array( $backend, array( 'api', 'smtp' ), true ) ? $backend : 'api' );

		update_option(
			'euromail_smtp_host',
			isset( $_POST['euromail_smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_smtp_host'] ) ) : ''
		);
		update_option(
			'euromail_smtp_port',
			isset( $_POST['euromail_smtp_port'] ) ? absint( $_POST['euromail_smtp_port'] ) : 587
		);

		$encryption = isset( $_POST['euromail_smtp_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_smtp_encryption'] ) ) : 'tls';
		update_option( 'euromail_smtp_encryption', in_array( $encryption, array( 'tls', 'ssl', 'none' ), true ) ? $encryption : 'tls' );

		update_option( 'euromail_smtp_auth', self::bool_option( isset( $_POST['euromail_smtp_auth'] ) ) );
		update_option(
			'euromail_smtp_username',
			isset( $_POST['euromail_smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_smtp_username'] ) ) : ''
		);

		if ( ! defined( 'EUROMAIL_SMTP_PASSWORD' ) || '' === EUROMAIL_SMTP_PASSWORD ) {
			update_option(
				'euromail_smtp_password',
				isset( $_POST['euromail_smtp_password'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_smtp_password'] ) ) : ''
			);
		}

		update_option(
			'euromail_log_retention_days',
			isset( $_POST['euromail_log_retention_days'] ) ? absint( $_POST['euromail_log_retention_days'] ) : 30
		);
		update_option( 'euromail_store_body', self::bool_option( isset( $_POST['euromail_store_body'] ) ) );
		update_option( 'euromail_delete_data_on_uninstall', self::bool_option( isset( $_POST['euromail_delete_data_on_uninstall'] ) ) );

		add_settings_error( 'euromail_settings', 'euromail_settings_saved', __( 'Settings saved.', 'euromail' ), 'updated' );
	}

	/**
	 * Convert a boolean to the '1'/'0' string WordPress reliably persists
	 * and retrieves. Storing a raw PHP `false` via update_option() is not
	 * safe: WordPress's options cache can end up treating it the same as
	 * "option not set" within the same request, silently falling back to
	 * whatever default get_option() was called with instead of the value
	 * actually saved. Euromail_Settings::get() casts back to a real bool.
	 *
	 * @param bool $value Value to persist.
	 * @return string
	 */
	private static function bool_option( $value ) {
		return $value ? '1' : '0';
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Backend', 'euromail' ); ?></th>
						<td>
							<label><input type="radio" name="euromail_test_backend" value="default" checked /> <?php esc_html_e( 'Default (from Settings)', 'euromail' ); ?></label>
							&nbsp;
							<label><input type="radio" name="euromail_test_backend" value="api" /> <?php esc_html_e( 'Force API', 'euromail' ); ?></label>
							&nbsp;
							<label><input type="radio" name="euromail_test_backend" value="smtp" /> <?php esc_html_e( 'Force SMTP', 'euromail' ); ?></label>
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

		$override_filter = $this->build_backend_override_filter();

		if ( null !== $override_filter ) {
			add_filter( 'euromail_backends', $override_filter );
		}

		$sent = wp_mail( $to, $subject, $message, $headers );

		if ( null !== $override_filter ) {
			remove_filter( 'euromail_backends', $override_filter );
		}

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

	/**
	 * Build a one-off `euromail_backends` filter callback that forces the
	 * Send Test page's chosen backend, bypassing the normal
	 * euromail_backend/euromail_fallback_enabled chain for this one send.
	 * Returns null for the "Default" choice, meaning: don't override,
	 * behave exactly as a real wp_mail() call would.
	 *
	 * @return callable|null
	 */
	private function build_backend_override_filter() {
		$choice = isset( $_POST['euromail_test_backend'] ) ? sanitize_text_field( wp_unslash( $_POST['euromail_test_backend'] ) ) : 'default';

		if ( 'api' === $choice && class_exists( 'Euromail_Api_Backend' ) ) {
			return function () {
				return array( 'api' => new Euromail_Api_Backend() );
			};
		}

		if ( 'smtp' === $choice && class_exists( 'Euromail_Smtp_Backend' ) ) {
			return function () {
				return array( 'smtp' => new Euromail_Smtp_Backend() );
			};
		}

		return null;
	}

	/**
	 * Render the Log page: a WP_List_Table of delivery attempts, with
	 * status filter views, Resend/Delete row actions, and a bulk Delete
	 * action — or, for `?action=view`, a single row's full detail.
	 */
	public function render_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'view' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_log_detail_page( absint( $_GET['id'] ) );
			return;
		}

		$this->maybe_handle_log_row_action();

		$table = new Euromail_Log_Table();
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Euromail Log', 'euromail' ); ?></h1>

			<?php $this->render_log_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="euromail-log" />
				<?php $table->views(); ?>
				<?php $table->search_box( __( 'Search', 'euromail' ), 'euromail-log-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the detail view for a single log row: every column, plus a
	 * readable preview of the stored payload. Read-only — no nonce needed,
	 * since viewing has no side effects.
	 *
	 * Events timeline (webhook delivery/open/click events) arrives in M4;
	 * the 'events' column is already reserved for it in the schema.
	 *
	 * @param int $id Log row ID.
	 */
	private function render_log_detail_page( $id ) {
		$row = Euromail_Logger::get( $id );

		$back_url = admin_url( 'admin.php?page=euromail-log' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Euromail Log Entry', 'euromail' ); ?></h1>

			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'euromail' ); ?></a></p>

			<?php if ( ! $row ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'This log entry no longer exists.', 'euromail' ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<table class="form-table">
				<tr><th scope="row"><?php esc_html_e( 'ID', 'euromail' ); ?></th><td><?php echo esc_html( $row['id'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Status', 'euromail' ); ?></th><td><?php echo esc_html( $row['status'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Backend', 'euromail' ); ?></th><td><?php echo esc_html( '' !== (string) $row['backend'] ? $row['backend'] : '—' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Created', 'euromail' ); ?></th><td><?php echo esc_html( $row['created_at'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Updated', 'euromail' ); ?></th><td><?php echo esc_html( $row['updated_at'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'From', 'euromail' ); ?></th><td><?php echo esc_html( $row['mail_from'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'To', 'euromail' ); ?></th><td><?php echo esc_html( $row['mail_to'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Subject', 'euromail' ); ?></th><td><?php echo esc_html( $row['subject'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Attempts', 'euromail' ); ?></th><td><?php echo esc_html( $row['attempts'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Next attempt', 'euromail' ); ?></th><td><?php echo esc_html( '' !== (string) $row['next_attempt_at'] ? $row['next_attempt_at'] : '—' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Message ID', 'euromail' ); ?></th><td><?php echo esc_html( '' !== (string) $row['message_id'] ? $row['message_id'] : '—' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Idempotency key', 'euromail' ); ?></th><td><code><?php echo esc_html( $row['idempotency_key'] ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Error', 'euromail' ); ?></th><td><?php echo esc_html( '' !== (string) $row['error'] ? $row['error'] : '—' ); ?></td></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Stored payload', 'euromail' ); ?></th>
					<td>
						<?php if ( empty( $row['payload'] ) ) : ?>
							<em><?php esc_html_e( 'Not stored.', 'euromail' ); ?></em>
						<?php else : ?>
							<pre style="max-width: 100%; overflow-x: auto; white-space: pre-wrap;"><?php echo esc_html( wp_json_encode( json_decode( $row['payload'], true ), JSON_PRETTY_PRINT ) ); ?></pre>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Print a notice for the outcome of a just-completed row action, based
	 * on the `euromail_log_notice` query var set by the post-action
	 * redirect in maybe_handle_log_row_action().
	 */
	private function render_log_notice() {
		if ( ! isset( $_GET['euromail_log_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['euromail_log_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'deleted'                   => array( 'success', __( 'Log entry deleted.', 'euromail' ) ),
			'resent'                    => array( 'success', __( 'Email resent successfully.', 'euromail' ) ),
			'resend_queued'             => array( 'success', __( 'Resend queued; it will retry automatically if it does not go out immediately.', 'euromail' ) ),
			'resend_failed'             => array( 'error', __( 'Could not resend this email — it has no stored payload to resend.', 'euromail' ) ),
			'resend_missing_attachment' => array( 'error', __( 'Could not resend this email — attachment "%s" no longer exists and cannot be resent.', 'euromail' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $messages[ $notice ];

		if ( 'resend_missing_attachment' === $notice && isset( $_GET['euromail_log_notice_detail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$text = sprintf( $text, sanitize_text_field( wp_unslash( $_GET['euromail_log_notice_detail'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/**
	 * Handle a Resend or Delete row action link, then redirect back to a
	 * clean log page URL (so reloading the page never repeats the action).
	 */
	private function maybe_handle_log_row_action() {
		if ( ! isset( $_GET['action'], $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = absint( $_GET['id'] );

		if ( ! in_array( $action, array( 'resend', 'delete' ), true ) || $id <= 0 ) {
			return;
		}

		check_admin_referer( 'euromail_log_action_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = $this->process_log_row_action( $action, $id );

		$redirect_args = array(
			'page'                => 'euromail-log',
			'euromail_log_notice' => $notice,
		);

		if ( null !== $this->last_resend_missing_attachment ) {
			$redirect_args['euromail_log_notice_detail'] = $this->last_resend_missing_attachment;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Perform a Resend or Delete row action and return the notice key for
	 * it. Split out from maybe_handle_log_row_action() so the actual
	 * behavior is testable without also triggering its redirect + exit.
	 *
	 * @param string $action 'resend' or 'delete'.
	 * @param int    $id     Log row ID.
	 * @return string Notice key: 'deleted', 'resent', 'resend_queued', 'resend_failed', or 'resend_missing_attachment'.
	 */
	public function process_log_row_action( $action, $id ) {
		if ( 'delete' === $action ) {
			Euromail_Logger::delete( $id );
			return 'deleted';
		}

		return $this->resend_log_row( $id );
	}

	/**
	 * Re-queue a log row for an immediate retry attempt: resets attempts to
	 * 0 (a manual resend gets a fresh full retry budget) and processes it
	 * synchronously so the admin gets an immediate result instead of
	 * waiting for the next cron tick. Reuses the row's existing idempotency
	 * key — if the original attempt actually reached the server despite the
	 * recorded failure, that same-key dedupe prevents a duplicate delivery;
	 * if it never arrived, the resend proceeds normally.
	 *
	 * A 'failed' row's stored payload may be redacted (attachment content
	 * stripped, per euromail_store_body) rather than absent, so any
	 * attachment missing its content is re-read fresh from its recorded
	 * 'path' before resending. If that path no longer exists, the resend is
	 * refused outright — never silently sent without the attachment.
	 *
	 * @param int $id Log row ID.
	 * @return string Notice key: 'resent', 'resend_queued', 'resend_failed', or 'resend_missing_attachment'.
	 */
	private function resend_log_row( $id ) {
		$this->last_resend_missing_attachment = null;

		$row = Euromail_Logger::get( $id );

		if ( ! $row || empty( $row['payload'] ) ) {
			return 'resend_failed';
		}

		$email = json_decode( $row['payload'], true );

		if ( ! is_array( $email ) ) {
			return 'resend_failed';
		}

		$missing_attachment = $this->rehydrate_attachments_for_resend( $email );

		if ( null !== $missing_attachment ) {
			$this->last_resend_missing_attachment = $missing_attachment;
			return 'resend_missing_attachment';
		}

		Euromail_Logger::update(
			$id,
			array(
				'status'          => 'queued',
				'attempts'        => 0,
				'error'           => null,
				'next_attempt_at' => current_time( 'mysql' ),
				'payload'         => wp_json_encode( $email ),
			)
		);

		Euromail_Queue::process_row( $id );

		$row = Euromail_Logger::get( $id );

		return ( $row && 'sent' === $row['status'] ) ? 'resent' : 'resend_queued';
	}

	/**
	 * Fill in 'content' for any attachment in a decoded payload that is
	 * missing it (a redacted terminal-row payload being resent), reading
	 * fresh bytes from its stored 'path'. Attachments that already carry
	 * content are left untouched. Mutates $email in place.
	 *
	 * @param array $email Canonical email array, modified by reference.
	 * @return string|null The filename of the first attachment whose file
	 *                      no longer exists, or null when every attachment
	 *                      could be resent.
	 */
	private function rehydrate_attachments_for_resend( array &$email ) {
		if ( empty( $email['attachments'] ) ) {
			return null;
		}

		foreach ( $email['attachments'] as &$attachment ) {
			if ( ! empty( $attachment['content'] ) ) {
				continue;
			}

			$path = isset( $attachment['path'] ) ? $attachment['path'] : '';

			if ( '' === $path || ! file_exists( $path ) ) {
				return isset( $attachment['filename'] ) ? $attachment['filename'] : $path;
			}

			$attachment['content'] = base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		unset( $attachment );

		return null;
	}
}
