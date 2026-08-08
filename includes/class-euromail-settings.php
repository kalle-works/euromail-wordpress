<?php
/**
 * Reads plugin configuration from options, with constant overrides.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central accessor for plugin settings.
 *
 * wp-config.php constants always win over the stored option, so hosts can
 * lock secrets outside the database.
 */
class Euromail_Settings {

	/**
	 * Default option values, keyed by option name.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'euromail_backend'                  => 'api',
		'euromail_api_key'                  => '',
		'euromail_api_base_url'             => 'https://api.euromail.dev',
		'euromail_force_from_enabled'       => false,
		'euromail_force_from_email'         => '',
		'euromail_force_from_name'          => '',
		'euromail_transactional_default'    => true,
		'euromail_tracking_default'         => false,
		'euromail_fallback_enabled'         => false,
		'euromail_log_retention_days'       => 30,
		'euromail_store_body'               => false,
		'euromail_delete_data_on_uninstall' => false,
		'euromail_smtp_host'                => '',
		'euromail_smtp_port'                => 587,
		'euromail_smtp_encryption'          => 'tls',
		'euromail_smtp_auth'                => true,
		'euromail_smtp_username'            => '',
		'euromail_smtp_password'            => '',
		'euromail_webhook_secret'           => '',
	);

	/**
	 * Maps an option name to the wp-config.php constant that overrides it.
	 *
	 * @var array
	 */
	const CONSTANT_OVERRIDES = array(
		'euromail_api_key'        => 'EUROMAIL_API_KEY',
		'euromail_api_base_url'   => 'EUROMAIL_API_BASE_URL',
		'euromail_smtp_password'  => 'EUROMAIL_SMTP_PASSWORD',
		'euromail_webhook_secret' => 'EUROMAIL_WEBHOOK_SECRET',
	);

	/**
	 * Get a setting value.
	 *
	 * @param string $key Option name, e.g. 'euromail_api_key'.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( isset( self::CONSTANT_OVERRIDES[ $key ] ) ) {
			$constant = self::CONSTANT_OVERRIDES[ $key ];

			if ( defined( $constant ) && '' !== constant( $constant ) ) {
				return constant( $constant );
			}
		}

		$is_known_key = array_key_exists( $key, self::DEFAULTS );
		$default      = $is_known_key ? self::DEFAULTS[ $key ] : false;
		$value        = get_option( $key, $default );

		// A registered boolean default means this option is always saved as
		// the '1'/'0' string Euromail_Admin::bool_option() produces (never
		// a raw PHP true/false — see that method's docblock for why), so
		// normalize it back to a real boolean here, in one place.
		if ( $is_known_key && is_bool( $default ) ) {
			return (bool) $value;
		}

		return $value;
	}

	/**
	 * Whether the plugin has enough configuration to attempt a send, via
	 * either backend.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::is_api_configured() || self::is_smtp_configured();
	}

	/**
	 * Whether the API backend has an API key set.
	 *
	 * @return bool
	 */
	public static function is_api_configured() {
		return '' !== (string) self::get( 'euromail_api_key' );
	}

	/**
	 * Whether the SMTP backend has enough configuration to attempt a send:
	 * a host, and (when auth is on) a username and password.
	 *
	 * @return bool
	 */
	public static function is_smtp_configured() {
		if ( '' === (string) self::get( 'euromail_smtp_host' ) ) {
			return false;
		}

		if ( ! self::get( 'euromail_smtp_auth' ) ) {
			return true;
		}

		return '' !== (string) self::get( 'euromail_smtp_username' )
			&& '' !== (string) self::get( 'euromail_smtp_password' );
	}
}
