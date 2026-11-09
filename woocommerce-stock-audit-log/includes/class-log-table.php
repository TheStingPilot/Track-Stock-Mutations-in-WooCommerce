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
		parent::__construct( array( 'singular' => 'stock-mutation', 'plural' => 'stock-mutations', 'ajax' => false ) );
		$this->filters = $filters;
		$this->report  = $report;
	}

	public function get_columns(): array {
		$columns = Admin::columns();
		if ( in_array( $this->report, array( 'loyverse', 'stock_history' ), true ) ) {
			$columns['notes'] = __( 'Receipt reference and notes', 'admin_texts_wc_stock_audittrail' );
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

	public function display(): void {
		$this->display_tablenav( 'top' );
		echo '<div class="wsal-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Stock audit entries', 'admin_texts_wc_stock_audittrail' ) . '">';
		echo '<table class="' . esc_attr( implode( ' ', $this->get_table_classes() ) ) . '"><thead><tr>';
		$this->print_column_headers();
		echo '</tr></thead><tbody id="the-list">';
		$this->display_rows_or_placeholder();
		echo '</tbody></table></div>';
		$this->display_tablenav( 'bottom' );
	}

	public function column_default( $item, $column_name ): string {
		return Admin::cell( $item, $column_name );
	}

	public function no_items(): void {
		esc_html_e( 'No stock mutations found.', 'admin_texts_wc_stock_audittrail' );
	}
}
