<?php
/**
 * Reads and writes rows in the delivery log table.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the {$wpdb->prefix}euromail_log table.
 */
class Euromail_Logger {

	/**
	 * Full, prefixed log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'euromail_log';
	}

	/**
	 * Insert a new log row.
	 *
	 * @param array $data Column values. Any column not provided falls back to its default.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$defaults = array(
			'created_at'      => $now,
			'updated_at'      => $now,
			'status'          => 'sending',
			'backend'         => null,
			'message_id'      => null,
			'idempotency_key' => wp_generate_uuid4(),
			'mail_from'       => '',
			'mail_to'         => '',
			'subject'         => '',
			'payload'         => null,
			'attempts'        => 0,
			'next_attempt_at' => null,
			'error'           => null,
			'events'          => null,
		);

		$row = array_merge( $defaults, $data );

		$wpdb->insert( self::table_name(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing log row. Always bumps updated_at.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column values to change.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table_name(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Fetch a single log row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}
}
