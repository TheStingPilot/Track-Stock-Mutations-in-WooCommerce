<?php
/**
 * Administration screens and export.
 *
 * @package WooCommerceStockAuditLog
 */

namespace StockAuditLog;

defined( 'ABSPATH' ) || exit;

final class Admin {
	const LOG      = 'stock-audit-log';
	const SETTINGS = 'stock-audit-settings';
	const VIEW     = 'manage_woocommerce';
	const MANAGE   = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'metabox' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ) );
		add_action( 'admin_post_stock_audit_export', array( $this, 'export' ) );
		add_action( 'admin_post_stock_audit_settings', array( $this, 'save_settings' ) );
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', 'Stock Audit Log', 'Stock Audit Log', self::VIEW, self::LOG, array( $this, 'screen' ) );
		add_submenu_page( 'woocommerce', 'Stock Audit Settings', 'Stock Audit Settings', self::MANAGE, self::SETTINGS, array( $this, 'settings' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::LOG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function input( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
	}

	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have access to this stock audit operation.', 'woocommerce-stock-audit-log' ), '', array( 'response' => 403 ) );
		}
	}

	private function raw_filters(): array {
		$filters = array();
		foreach ( array( 'from_date', 'to_date', 'product_id', 'sku', 'source', 'user_id', 'order_number', 's' ) as $key ) {
			$value = self::input( $_GET, $key );
			if ( '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}
		return $filters;
	}

	private function filters( array $raw, string $report ): array {
		$filters = $raw;
		foreach ( array( 'product_id', 'user_id' ) as $key ) {
			if ( isset( $filters[ $key ] ) && ( ! ctype_digit( $filters[ $key ] ) || (int) $filters[ $key ] < 1 ) ) {
				wp_die( esc_html__( 'Product and user IDs must be positive integers.', 'woocommerce-stock-audit-log' ), '', array( 'response' => 400 ) );
			}
		}
		if ( isset( $filters['source'] ) && ! in_array( $filters['source'], Storage::SOURCES, true ) ) {
			wp_die( esc_html__( 'Invalid stock source.', 'woocommerce-stock-audit-log' ), '', array( 'response' => 400 ) );
		}

		foreach ( array( 'from_date' => 'from', 'to_date' => 'until' ) as $field => $boundary ) {
			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw[ $field ], wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $raw[ $field ] ) {
				wp_die( esc_html__( 'Use dates in YYYY-MM-DD format.', 'woocommerce-stock-audit-log' ), '', array( 'response' => 400 ) );
			}
			if ( 'until' === $boundary ) {
				$date = $date->modify( '+1 day' );
			}
			$filters[ $boundary ] = gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
		}

		if ( 'manual' === $report ) {
			$filters['source'] = 'WP Admin';
		} elseif ( 'loyverse' === $report ) {
			$filters['source'] = 'Loyverse API';
		}
		return $filters;
	}

	public function screen(): void {
		$this->authorize( self::VIEW );
		$id = absint( self::input( $_GET, 'entry' ) );
		if ( $id ) {
			$this->detail( $id );
			return;
		}

		$report = self::input( $_GET, 'report' );
		$raw = $this->raw_filters();
		$filters = $this->filters( $raw, $report );
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock Audit Log', 'woocommerce-stock-audit-log' ) . '</h1><nav class="nav-tab-wrapper" aria-label="Stock audit reports">';
		foreach ( array( '' => 'All mutations', 'top' => 'Top 50 products', 'manual' => 'Manual corrections', 'loyverse' => 'Loyverse mutations' ) as $key => $title ) {
			$args = $raw;
			unset( $args['source'] );
			$args['report'] = $key;
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$report === $key ? ' nav-tab-active' : '',
				esc_url( self::url( $args ) ),
				esc_html( $title )
			);
		}
		echo '</nav>';
		$this->filter_form( $raw, $report );

		if ( 'top' === $report ) {
			$this->render_table(
				array( 'sku' => 'SKU', 'product_name' => 'Product', 'mutations' => 'Mutations' ),
				Storage::top_products( $filters )
			);
		} else {
			$export_url = add_query_arg( array_merge( $raw, array( 'action' => 'stock_audit_export', 'report' => $report ) ), admin_url( 'admin-post.php' ) );
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( wp_nonce_url( $export_url, 'stock_audit_export' ) ), esc_html__( 'Export CSV', 'woocommerce-stock-audit-log' ) );
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once __DIR__ . '/class-log-table.php';
			$table = new Log_Table( $filters, $report );
			$table->prepare_items();
			$table->display();
		}
		echo '</div>';
	}

	private function filter_form( array $raw, string $report ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><p>';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="report" value="%s">', esc_attr( self::LOG ), esc_attr( $report ) );
		$fields = array(
			'from_date'  => array( 'From date', 'date' ),
			'to_date'    => array( 'Through date', 'date' ),
			'product_id' => array( 'Product or variation ID', 'number' ),
			'sku'        => array( 'Exact SKU', 'text' ),
			'user_id'    => array( 'User ID', 'number' ),
			'order_number' => array( 'Order number or ID', 'text' ),
			's'          => array( 'Search', 'search' ),
		);
		foreach ( $fields as $name => list( $label, $type ) ) {
			printf(
				'<label style="display:inline-block;margin:4px">%s <input type="%s" name="%s" value="%s"%s></label> ',
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $name ),
				esc_attr( $raw[ $name ] ?? '' ),
				'number' === $type ? ' min="1"' : ''
			);
		}
		if ( ! in_array( $report, array( 'manual', 'loyverse' ), true ) ) {
			echo '<label>' . esc_html__( 'Source', 'woocommerce-stock-audit-log' ) . ' <select name="source"><option value="">' . esc_html__( 'All sources', 'woocommerce-stock-audit-log' ) . '</option>';
			foreach ( Storage::SOURCES as $source ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $source ), selected( $raw['source'] ?? '', $source, false ), esc_html( $source ) );
			}
			echo '</select></label> ';
		}
		submit_button( __( 'Apply filters', 'woocommerce-stock-audit-log' ), 'secondary', 'filter_action', false );
		printf( ' <a href="%s">%s</a>', esc_url( self::url( array( 'report' => $report ) ) ), esc_html__( 'Clear', 'woocommerce-stock-audit-log' ) );
		echo '</p></form>';
	}

	public static function columns(): array {
		return array(
			'timestamp'    => 'Date and time',
			'product_name' => 'Product',
			'sku'          => 'SKU',
			'old_stock'    => 'Old stock',
			'new_stock'    => 'New stock',
			'difference'   => 'Difference',
			'source'       => 'Source',
			'username'     => 'User',
			'order_id'     => 'Order number',
		);
	}

	public static function cell( object $row, string $field ): string {
		$value = $row->$field ?? null;
		if ( null === $value || '' === $value ) {
			return '&mdash;';
		}

		if ( 'timestamp' === $field ) {
			$display = get_date_from_gmt( $value, 'Y-m-d H:i:s' );
			return sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'entry' => $row->id ) ) ), esc_html( $display ) );
		}
		if ( 'order_id' === $field ) {
			$order = wc_get_order( (int) $value );
			if ( $order ) {
				$label = '#' . ( $row->order_number ?? $order->get_order_number() );
				if ( current_user_can( 'edit_shop_order', $order->get_id() ) ) {
					return sprintf( '<a href="%s">%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $label ) );
				}
				return esc_html( $label );
			}
			return esc_html( '#' . ( $row->order_number ?? $value ) );
		}
		if ( 'product_name' === $field && current_user_can( 'edit_post', (int) $row->product_id ) ) {
			$url = get_edit_post_link( (int) $row->product_id );
			if ( $url ) {
				return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $value ) );
			}
		}
		return esc_html( (string) $value );
	}

	private function detail( int $id ): void {
		$row = Storage::get( $id );
		if ( ! $row ) {
			wp_die( esc_html__( 'Stock audit entry not found.', 'woocommerce-stock-audit-log' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock audit entry', 'woocommerce-stock-audit-log' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to Stock Audit Log', 'woocommerce-stock-audit-log' ) );
		echo '<table class="widefat striped"><tbody>';
		$fields = self::columns() + array(
			'product_id' => 'Product ID', 'variation_id' => 'Variation ID', 'user_id' => 'User ID',
			'request_uri' => 'Request URI', 'ip_address' => 'IP address', 'notes' => 'Notes',
		);
		foreach ( $fields as $field => $label ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( self::cell( $row, $field ) ) );
		}
		echo '</tbody></table></div>';
	}

	public function metabox(): void {
		if ( current_user_can( self::VIEW ) ) {
			add_meta_box( 'stock-audit-history', 'Stock Audit History', array( $this, 'product_history' ), 'product' );
		}
	}

	public function product_history( \WP_Post $post ): void {
		$this->authorize( self::VIEW );
		$columns = array_diff_key( self::columns(), array( 'product_name' => true, 'sku' => true ) );
		$this->render_table( $columns, Storage::rows( array( 'product_id' => $post->ID ), 20 ) );
	}

	public function dashboard(): void {
		if ( current_user_can( self::VIEW ) ) {
			wp_add_dashboard_widget( 'stock_audit_latest', 'Latest stock mutations', array( $this, 'dashboard_rows' ) );
		}
	}

	public function dashboard_rows(): void {
		$this->authorize( self::VIEW );
		$columns = array_intersect_key( self::columns(), array_flip( array( 'timestamp', 'product_name', 'difference', 'source' ) ) );
		$this->render_table( $columns, Storage::rows( array(), 25 ) );
	}

	private function render_table( array $columns, array $rows ): void {
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No stock mutations found.', 'woocommerce-stock-audit-log' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $columns as $label ) {
			printf( '<th scope="col">%s</th>', esc_html( $label ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $field => $label ) {
				printf( '<td>%s</td>', wp_kses_post( self::cell( $row, $field ) ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function settings(): void {
		$this->authorize( self::MANAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock Audit Settings', 'woocommerce-stock-audit-log' ) . '</h1>';
		if ( '1' === self::input( $_GET, 'saved' ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'woocommerce-stock-audit-log' ) . '</p></div>';
		}
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="stock_audit_settings">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'stock_audit_settings' );
		echo '<label>' . esc_html__( 'Retention', 'woocommerce-stock-audit-log' ) . ' <select name="retention_days">';
		$current = Storage::retention();
		foreach ( Storage::RETENTION as $days ) {
			printf( '<option value="%d"%s>%s</option>', $days, selected( $current, $days, false ), esc_html( $days ? sprintf( '%d days', $days ) : 'Unlimited' ) );
		}
		echo '</select></label><p>' . esc_html__( 'Scheduled cleanup permanently removes entries older than the selected retention period.', 'woocommerce-stock-audit-log' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	public function save_settings(): void {
		$this->authorize( self::MANAGE );
		check_admin_referer( 'stock_audit_settings' );
		$days = self::input( $_POST, 'retention_days' );
		if ( ! ctype_digit( $days ) || ! Storage::set_retention( (int) $days ) ) {
			wp_die( esc_html__( 'The retention setting could not be saved.', 'woocommerce-stock-audit-log' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function export(): void {
		$this->authorize( self::VIEW );
		check_admin_referer( 'stock_audit_export' );
		$filters = $this->filters( $this->raw_filters(), self::input( $_GET, 'report' ) );
		$output = fopen( 'php://output', 'wb' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV stream could not be opened.', 'woocommerce-stock-audit-log' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="stock-audit-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$fields = array( 'id', 'timestamp', 'product_id', 'variation_id', 'sku', 'product_name', 'old_stock', 'new_stock', 'difference', 'source', 'user_id', 'username', 'order_id', 'order_number', 'request_uri', 'ip_address', 'notes' );
		$numeric = array( 'id', 'product_id', 'variation_id', 'old_stock', 'new_stock', 'difference', 'user_id', 'order_id' );
		$headings = $fields;
		$headings[1] = 'timestamp_utc';
		fputcsv( $output, $headings, ',', '"', '' );
		$cursor = 0;
		do {
			$rows = Storage::rows( $filters, 500, 0, $cursor, true );
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $fields as $field ) {
					$value = (string) ( $row->$field ?? '' );
					if ( ! in_array( $field, $numeric, true ) && preg_match( '/^(?:[\t\r\n]|\s*[=+@-])/u', $value ) ) {
						$value = "'" . $value;
					}
					$line[] = $value;
				}
				fputcsv( $output, $line, ',', '"', '' );
				$cursor = (int) $row->id;
			}
		} while ( 500 === count( $rows ) && ! connection_aborted() );
		fclose( $output );
		exit;
	}
}
