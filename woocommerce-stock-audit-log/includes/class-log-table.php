<?php
/**
 * Paginated audit table.
 *
 * @package WooCommerceStockAuditLog
 */

namespace StockAuditLog;

defined( 'ABSPATH' ) || exit;

final class Log_Table extends \WP_List_Table {
	private array $filters;
	private string $report;

	public function __construct( array $filters, string $report ) {
		parent::__construct( array( 'singular' => 'stock mutation', 'plural' => 'stock mutations', 'ajax' => false ) );
		$this->filters = $filters;
		$this->report  = $report;
	}

	public function get_columns(): array {
		$columns = Admin::columns();
		if ( 'loyverse' === $this->report ) {
			$columns['notes'] = 'Receipt reference and notes';
		}
		return $columns;
	}

	public function prepare_items(): void {
		$size = 50;
		$total = Storage::count( $this->filters );
		if ( ! $this->filters ) {
			$total = min( 500, $total );
		}
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'timestamp' );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $size ) );
		$offset = ( $this->get_pagenum() - 1 ) * $size;
		$this->items = $offset < $total ? Storage::rows( $this->filters, min( $size, $total - $offset ), $offset ) : array();
	}

	public function column_default( $item, $column_name ): string {
		return Admin::cell( $item, $column_name );
	}

	public function no_items(): void {
		esc_html_e( 'No stock mutations found.', 'woocommerce-stock-audit-log' );
	}
}
