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
	 * @return int Inserted row ID, or 0 on failure. Never trust a stale
	 *              $wpdb->insert_id from a previous successful insert in the
	 *              same request; the insert's own return value is checked.
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

		$inserted = $wpdb->insert( self::table_name(), $row );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an existing log row. Always bumps updated_at. A no-op (never
	 * touches the database) for id 0, since that means the row was never
	 * created in the first place.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column values to change.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
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
