<?php
/**
 * Tests for Euromail_Log_Table.
 *
 * Guarantees under test:
 * - The bulk "Delete" action actually deletes the checked rows. The
 *   surrounding page form uses method="get" (it doubles as the
 *   search/status-filter form), so the checked row IDs and bulk-action
 *   nonce arrive via $_GET — a handler reading only $_POST would silently
 *   do nothing.
 * - A row NOT checked is left alone.
 *
 * @package Euromail
 */

class Test_Euromail_Log_Table extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$_REQUEST = array();
		$_GET     = array();
		$_POST    = array();
		parent::tear_down();
	}

	private function insert_row( array $overrides = array() ) {
		$defaults = array(
			'status'          => 'sent',
			'idempotency_key' => wp_generate_uuid4(),
			'mail_to'         => 'recipient@example.com',
			'subject'         => 'Log table test',
		);

		return Euromail_Logger::create( array_merge( $defaults, $overrides ) );
	}

	public function test_bulk_delete_removes_the_checked_rows_via_get_request() {
		$to_delete_1 = $this->insert_row();
		$to_delete_2 = $this->insert_row();
		$to_keep     = $this->insert_row();

		// WP_List_Table's bulk-action form on this page submits via GET,
		// not POST — simulate that directly rather than $_POST, which is
		// exactly the distinction this fix is guarding.
		$_REQUEST['action'] = 'delete';
		$_REQUEST['log']    = array( (string) $to_delete_1, (string) $to_delete_2 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-euromail_logs' );
		$_GET = $_REQUEST;

		$table = new Euromail_Log_Table();
		$table->prepare_items();

		$this->assertNull( Euromail_Logger::get( $to_delete_1 ), 'A checked row must be deleted.' );
		$this->assertNull( Euromail_Logger::get( $to_delete_2 ), 'A checked row must be deleted.' );
		$this->assertNotNull( Euromail_Logger::get( $to_keep ), 'A row that was not checked must be left alone.' );
	}

	public function test_no_bulk_action_leaves_all_rows_untouched() {
		$id = $this->insert_row();

		$_REQUEST['action'] = '-1';
		$_GET                = $_REQUEST;

		$table = new Euromail_Log_Table();
		$table->prepare_items();

		$this->assertNotNull( Euromail_Logger::get( $id ) );
	}
}
