<?php
/**
 * Delivery log list table, shown on the Euromail Log admin page.
 *
 * @package Euromail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Euromail_Log_Table extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'euromail_log',
				'plural'   => 'euromail_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'euromail' ),
			'status'     => __( 'Status', 'euromail' ),
			'backend'    => __( 'Backend', 'euromail' ),
			'mail_to'    => __( 'To', 'euromail' ),
			'subject'    => __( 'Subject', 'euromail' ),
			'attempts'   => __( 'Attempts', 'euromail' ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'status'     => array( 'status', false ),
		);
	}

	/**
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'subject';
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'euromail' ),
		);
	}

	public function no_items() {
		esc_html_e( 'No emails logged yet.', 'euromail' );
	}

	/**
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="log[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Subject column doubles as the primary column, carrying the
	 * Resend/Delete row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_subject( $item ) {
		$subject = '' !== $item['subject'] ? $item['subject'] : __( '(no subject)', 'euromail' );

		$nonce_action = 'euromail_log_action_' . $item['id'];
		$actions      = array();

		if ( in_array( $item['status'], array( 'failed', 'queued' ), true ) && ! empty( $item['payload'] ) ) {
			$resend_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'euromail-log',
						'action' => 'resend',
						'id'     => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				$nonce_action
			);

			$actions['resend'] = sprintf( '<a href="%s">%s</a>', esc_url( $resend_url ), esc_html__( 'Resend', 'euromail' ) );
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'euromail-log',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			$nonce_action
		);

		$actions['delete'] = sprintf(
			'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Delete this log entry? This cannot be undone.', 'euromail' ) ),
			esc_html__( 'Delete', 'euromail' )
		);

		return esc_html( $subject ) . $this->row_actions( $actions );
	}

	/**
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'backend':
				return '' !== (string) $item['backend'] ? esc_html( $item['backend'] ) : '&#8212;';

			case 'created_at':
			case 'status':
			case 'mail_to':
			case 'attempts':
				return esc_html( $item[ $column_name ] );

			default:
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->process_bulk_delete();

		global $wpdb;
		$table = Euromail_Logger::table_name();

		$where        = array();
		$where_values = array();

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $search ) {
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]        = '(mail_to LIKE %s OR subject LIKE %s)';
			$where_values[] = $like;
			$where_values[] = $like;
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

		$sortable_columns = array( 'created_at', 'status' );
		$orderby          = isset( $_REQUEST['orderby'] ) && in_array( wp_unslash( $_REQUEST['orderby'] ), $sortable_columns, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'created_at';
		$order            = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total_items = $where_values
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_values ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * self::PER_PAGE;

		$items_sql    = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query_values = array_merge( $where_values, array( self::PER_PAGE, $offset ) );

		$this->items = $wpdb->get_results( $wpdb->prepare( $items_sql, $query_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total_items / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Handle the bulk "Delete" action.
	 */
	private function process_bulk_delete() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		if ( empty( $_POST['log'] ) || ! is_array( $_POST['log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( wp_unslash( $_POST['log'] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			Euromail_Logger::delete( absint( $id ) );
		}
	}
}
