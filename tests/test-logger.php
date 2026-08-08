<?php
/**
 * Tests for Euromail_Logger.
 *
 * Guarantees under test:
 * - create() returns 0 when the insert genuinely fails, never a stale
 *   $wpdb->insert_id left over from an earlier, unrelated successful
 *   insert in the same request.
 * - update() is a no-op (touches no row, returns false) for id 0 or below,
 *   since that means the row was never created in the first place.
 *
 * @package Euromail
 */

class Test_Euromail_Logger extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'query' );
		global $wpdb;
		$wpdb->suppress_errors( false );
		parent::tear_down();
	}

	private function base_row() {
		return array(
			'idempotency_key' => wp_generate_uuid4(),
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Logger test',
		);
	}

	public function test_create_returns_zero_when_insert_fails_even_after_an_earlier_successful_insert() {
		// Poison $wpdb->insert_id with a real, positive value from a
		// genuinely successful insert first — this is what a stale-ID bug
		// would incorrectly return on the next, failing insert.
		$first_id = Euromail_Logger::create( $this->base_row() );
		$this->assertGreaterThan( 0, $first_id );

		global $wpdb;
		$table = Euromail_Logger::table_name();

		$break_insert = function ( $query ) use ( $table ) {
			if ( 0 === strpos( ltrim( $query ), 'INSERT INTO' ) && false !== strpos( $query, $table ) ) {
				return 'SELECT 1 FROM `' . $table . '` WHERE 1 = 0 AND this_column_does_not_exist = 1';
			}
			return $query;
		};

		$wpdb->suppress_errors( true );
		add_filter( 'query', $break_insert );

		$second_id = Euromail_Logger::create( $this->base_row() );

		remove_filter( 'query', $break_insert );
		$wpdb->suppress_errors( false );

		$this->assertSame( 0, $second_id, 'A failed insert must return 0, not the previous successful insert_id.' );
	}

	public function test_update_is_a_noop_for_id_zero() {
		$result = Euromail_Logger::update( 0, array( 'status' => 'sent' ) );

		$this->assertFalse( $result );
	}

	public function test_update_is_a_noop_for_negative_id() {
		$result = Euromail_Logger::update( -1, array( 'status' => 'sent' ) );

		$this->assertFalse( $result );
	}

	public function test_update_still_works_normally_for_a_real_id() {
		$id = Euromail_Logger::create( $this->base_row() );

		$result = Euromail_Logger::update( $id, array( 'status' => 'sent' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'sent', Euromail_Logger::get( $id )['status'] );
	}
}
