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
	);

	/**
	 * Maps an option name to the wp-config.php constant that overrides it.
	 *
	 * @var array
	 */
	const CONSTANT_OVERRIDES = array(
		'euromail_api_key'      => 'EUROMAIL_API_KEY',
		'euromail_api_base_url' => 'EUROMAIL_API_BASE_URL',
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

		$default = array_key_exists( $key, self::DEFAULTS ) ? self::DEFAULTS[ $key ] : false;

		return get_option( $key, $default );
	}

	/**
	 * Whether the plugin has enough configuration to attempt a send.
	 *
	 * SMTP-only configuration arrives in a later milestone; for now this
	 * only checks for an API key.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== (string) self::get( 'euromail_api_key' );
	}
}
