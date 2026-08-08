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
			'api_id'          => null,
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

	/**
	 * Permanently delete a log row.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return false !== $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Fetch a single log row by the euromail.dev API's own id for the send
	 * (SentEmail::$id — distinct from message_id) — how a webhook event
	 * identifies which email it belongs to.
	 *
	 * @param string $api_id API id.
	 * @return array|null
	 */
	public static function get_by_api_id( $api_id ) {
		global $wpdb;

		if ( '' === (string) $api_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE api_id = %s LIMIT 1', (string) $api_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * IDs of rows due for a retry attempt, oldest-touched first: a
	 * 'queued' row whose next_attempt_at is due, OR a 'sending' row that
	 * has been stuck there since before $stale_sending_before — its
	 * worker crashed mid-send (a fatal error, a killed process, a server
	 * restart) without ever reaching a terminal state or getting claimed
	 * back to 'queued', so it would otherwise sit invisible forever.
	 *
	 * @param int    $limit                Maximum number of IDs to return.
	 * @param string $now                  MySQL datetime to compare next_attempt_at against; defaults to now.
	 * @param string $stale_sending_before MySQL datetime; a 'sending' row last touched at or before this is considered stale. Defaults to $now.
	 * @return int[]
	 */
	public static function due_queue_ids( $limit, $now = null, $stale_sending_before = null ) {
		global $wpdb;

		if ( null === $now ) {
			$now = current_time( 'mysql' );
		}

		if ( null === $stale_sending_before ) {
			$stale_sending_before = $now;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table_name() . ' WHERE ( status = %s AND next_attempt_at <= %s ) OR ( status = %s AND updated_at <= %s ) ORDER BY updated_at ASC LIMIT %d',
				'queued',
				$now,
				'sending',
				$stale_sending_before,
				(int) $limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Atomically claim a row for processing by flipping its status to
	 * 'sending': either a 'queued' row (the normal case), or a 'sending'
	 * row that has been stale since before $stale_sending_before (a
	 * crashed worker's abandoned claim, reclaimed by a later run). Only
	 * one concurrent caller can win this for a given row — the UPDATE's
	 * WHERE clause is re-checked against the row's current committed
	 * state, so a second caller (an overlapping cron run, a manual
	 * `wp cron event run` racing the schedule, another process that
	 * reclaimed this same stale row microseconds earlier) affects zero
	 * rows and knows to back off.
	 *
	 * @param int         $id                   Row ID.
	 * @param string|null $stale_sending_before MySQL datetime; a 'sending' row last touched at or before this may be reclaimed. Defaults to now.
	 * @return bool True if this call claimed the row.
	 */
	public static function claim_for_retry( $id, $stale_sending_before = null ) {
		global $wpdb;

		if ( null === $stale_sending_before ) {
			$stale_sending_before = current_time( 'mysql' );
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET status = 'sending', updated_at = %s WHERE id = %d AND ( status = 'queued' OR ( status = 'sending' AND updated_at <= %s ) )",
				current_time( 'mysql' ),
				(int) $id,
				$stale_sending_before
			)
		);

		return 1 === $affected;
	}
}
