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
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		add_filter( 'post_row_actions', array( $this, 'product_row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_product', array( $this, 'metabox' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ) );
		add_action( 'admin_post_stock_audit_export', array( $this, 'export' ) );
		add_action( 'admin_post_stock_audit_settings', array( $this, 'save_settings' ) );
	}

	public function styles(): void {
		$screen = get_current_screen();
		if ( $screen && ( 'dashboard' === $screen->id || 'product' === $screen->id || self::LOG === self::input( $_GET, 'page' ) ) ) {
			wp_enqueue_style( 'stock-audit-log-admin', plugins_url( '../assets/admin.css', __FILE__ ), array(), '0.0.3' );
		}
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Stock Audit Log', 'admin_texts_wc_stock_audittrail' ), __( 'Stock Audit Log', 'admin_texts_wc_stock_audittrail' ), self::VIEW, self::LOG, array( $this, 'screen' ) );
		add_submenu_page( 'woocommerce', __( 'Stock Audit Settings', 'admin_texts_wc_stock_audittrail' ), __( 'Stock Audit Settings', 'admin_texts_wc_stock_audittrail' ), self::MANAGE, self::SETTINGS, array( $this, 'settings' ) );
	}

	private function upgrade_schema(): void {
		if ( current_user_can( self::MANAGE ) && ! Storage::upgrade_quantities() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The whole-stock schema upgrade could not be completed. Check the database and reopen the audit page to retry.', 'admin_texts_wc_stock_audittrail' ) . '</p></div>';
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::LOG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function input( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
	}

	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have access to this stock audit operation.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 403 ) );
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
				wp_die( esc_html__( 'Product and user IDs must be positive integers.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 400 ) );
			}
		}
		if ( isset( $filters['source'] ) && ! in_array( $filters['source'], Storage::SOURCES, true ) ) {
			wp_die( esc_html__( 'Invalid stock source.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 400 ) );
		}

		foreach ( array( 'from_date' => 'from', 'to_date' => 'until' ) as $field => $boundary ) {
			if ( ! isset( $raw[ $field ] ) ) {
				continue;
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw[ $field ], wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $raw[ $field ] ) {
				wp_die( esc_html__( 'Use dates in YYYY-MM-DD format.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 400 ) );
			}
			if ( 'until' === $boundary ) {
				$date = $date->modify( '+1 day' );
			}
			$filters[ $boundary ] = gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
		}

		if ( 'manual' === $report ) {
			$filters['source'] = 'WP Admin';
		} elseif ( 'loyverse' === $report ) {
			unset( $filters['source'] );
			$filters['loyverse_only'] = true;
		}
		return $filters;
	}

	public function screen(): void {
		$this->authorize( self::VIEW );
		$this->upgrade_schema();
		$id = absint( self::input( $_GET, 'entry' ) );
		if ( $id ) {
			$this->detail( $id );
			return;
		}

		$owner_id = absint( self::input( $_GET, 'stock_history' ) );
		if ( $owner_id ) {
			$this->stock_history( $owner_id );
			return;
		}

		$report = self::input( $_GET, 'report' );
		$raw = $this->raw_filters();
		$filters = $this->filters( $raw, $report );
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock Audit Log', 'admin_texts_wc_stock_audittrail' ) . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Stock audit reports', 'admin_texts_wc_stock_audittrail' ) . '">';
		foreach ( array( '' => __( 'All mutations', 'admin_texts_wc_stock_audittrail' ), 'top' => __( 'Top 50 products', 'admin_texts_wc_stock_audittrail' ), 'manual' => __( 'Manual corrections', 'admin_texts_wc_stock_audittrail' ), 'loyverse' => __( 'Loyverse mutations', 'admin_texts_wc_stock_audittrail' ) ) as $key => $title ) {
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
				array( 'sku' => __( 'SKU', 'admin_texts_wc_stock_audittrail' ), 'product_name' => __( 'Product', 'admin_texts_wc_stock_audittrail' ), 'mutations' => __( 'Mutations', 'admin_texts_wc_stock_audittrail' ) ),
				Storage::top_products( $filters )
			);
		} else {
			$export_url = add_query_arg( array_merge( $raw, array( 'action' => 'stock_audit_export', 'report' => $report ) ), admin_url( 'admin-post.php' ) );
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( wp_nonce_url( $export_url, 'stock_audit_export' ) ), esc_html__( 'Export CSV', 'admin_texts_wc_stock_audittrail' ) );
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once __DIR__ . '/class-log-table.php';
			$table = new Log_Table( $filters, $report );
			$table->prepare_items();
			$table->display();
			if ( 'loyverse' === $report && ! $raw ) {
				$this->loyverse_history();
			}
		}
		echo '</div>';
	}

	private function loyverse_history(): void {
		$before = absint( self::input( $_GET, 'plan_before' ) );
		$rows   = Storage::loyverse_history( $before );
		if ( ! $rows && ! $before ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Local Loyverse import history', 'admin_texts_wc_stock_audittrail' ) . '</h2>';
		echo '<p>' . esc_html__( 'Verified stock plans from the importer. The date is when the importer verified the plan. This separate history may overlap with audit entries and is excluded from the audit CSV.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		foreach ( $rows as $row ) {
			$row->verified_at = get_date_from_gmt( $row->updated_at, 'Y-m-d H:i:s' );
		}
		$page = array_slice( $rows, 0, 50 );
		$this->render_table(
			array(
				'verified_at'        => __( 'Verified at', 'admin_texts_wc_stock_audittrail' ),
				'sku'                => __( 'SKU', 'admin_texts_wc_stock_audittrail' ),
				'expected_old_stock' => __( 'Expected old stock', 'admin_texts_wc_stock_audittrail' ),
				'planned_new_stock'  => __( 'Verified stock', 'admin_texts_wc_stock_audittrail' ),
				'stock_delta'        => __( 'Planned difference', 'admin_texts_wc_stock_audittrail' ),
				'receipt_numbers'    => __( 'Receipt references', 'admin_texts_wc_stock_audittrail' ),
			),
			$page
		);
		echo '<p>';
		if ( $before ) {
			printf( '<a href="%s">%s</a> ', esc_url( self::url( array( 'report' => 'loyverse' ) ) ), esc_html__( 'Latest plans', 'admin_texts_wc_stock_audittrail' ) );
		}
		if ( count( $rows ) > 50 ) {
			printf( '<a href="%s">%s</a>', esc_url( self::url( array( 'report' => 'loyverse', 'plan_before' => end( $page )->id ) ) ), esc_html__( 'Older plans', 'admin_texts_wc_stock_audittrail' ) );
		}
		echo '</p>';
	}

	private function filter_form( array $raw, string $report ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><p>';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="report" value="%s">', esc_attr( self::LOG ), esc_attr( $report ) );
		$fields = array(
			'from_date'  => array( __( 'From date', 'admin_texts_wc_stock_audittrail' ), 'date' ),
			'to_date'    => array( __( 'Through date', 'admin_texts_wc_stock_audittrail' ), 'date' ),
			'product_id' => array( __( 'Product or variation ID', 'admin_texts_wc_stock_audittrail' ), 'number' ),
			'sku'        => array( __( 'Exact SKU', 'admin_texts_wc_stock_audittrail' ), 'text' ),
			'user_id'    => array( __( 'User ID', 'admin_texts_wc_stock_audittrail' ), 'number' ),
			'order_number' => array( __( 'Order number or ID', 'admin_texts_wc_stock_audittrail' ), 'text' ),
			's'          => array( __( 'Search', 'admin_texts_wc_stock_audittrail' ), 'search' ),
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
			echo '<label>' . esc_html__( 'Source', 'admin_texts_wc_stock_audittrail' ) . ' <select name="source"><option value="">' . esc_html__( 'All sources', 'admin_texts_wc_stock_audittrail' ) . '</option>';
			foreach ( Storage::SOURCES as $source ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $source ), selected( $raw['source'] ?? '', $source, false ), esc_html( self::source_label( $source ) ) );
			}
			echo '</select></label> ';
		}
		submit_button( __( 'Apply filters', 'admin_texts_wc_stock_audittrail' ), 'secondary', 'filter_action', false );
		printf( ' <a href="%s">%s</a>', esc_url( self::url( array( 'report' => $report ) ) ), esc_html__( 'Clear', 'admin_texts_wc_stock_audittrail' ) );
		echo '</p></form>';
	}

	public static function columns(): array {
		return array(
			'timestamp'    => __( 'Date and time', 'admin_texts_wc_stock_audittrail' ),
			'product_name' => __( 'Product', 'admin_texts_wc_stock_audittrail' ),
			'sku'          => __( 'SKU', 'admin_texts_wc_stock_audittrail' ),
			'old_stock'    => __( 'Old stock', 'admin_texts_wc_stock_audittrail' ),
			'new_stock'    => __( 'New stock', 'admin_texts_wc_stock_audittrail' ),
			'difference'   => __( 'Difference', 'admin_texts_wc_stock_audittrail' ),
			'source'       => __( 'Source', 'admin_texts_wc_stock_audittrail' ),
			'username'     => __( 'User', 'admin_texts_wc_stock_audittrail' ),
			'order_id'     => __( 'Order number', 'admin_texts_wc_stock_audittrail' ),
		);
	}

	private static function source_label( string $source ): string {
		return match ( $source ) {
			'WooCommerce Order' => __( 'WooCommerce Order', 'admin_texts_wc_stock_audittrail' ),
			'Loyverse API' => __( 'Loyverse API', 'admin_texts_wc_stock_audittrail' ),
			'Loyverse Import' => __( 'Loyverse Import', 'admin_texts_wc_stock_audittrail' ),
			'REST API' => __( 'REST API', 'admin_texts_wc_stock_audittrail' ),
			'WP Admin' => __( 'WP Admin', 'admin_texts_wc_stock_audittrail' ),
			'WP Cron' => __( 'WP Cron', 'admin_texts_wc_stock_audittrail' ),
			'WP-CLI' => __( 'WP-CLI', 'admin_texts_wc_stock_audittrail' ),
			'Unknown' => __( 'Unknown', 'admin_texts_wc_stock_audittrail' ),
			default => $source,
		};
	}

	public static function cell( object $row, string $field ): string {
		$value = $row->$field ?? null;
		if ( null === $value || '' === $value ) {
			return '&mdash;';
		}

		if ( in_array( $field, array( 'old_stock', 'new_stock', 'difference' ), true ) ) {
			return esc_html( number_format_i18n( (float) $value ) );
		}
		if ( 'source' === $field ) {
			return esc_html( self::source_label( $value ) );
		}
		if ( 'notes' === $field ) {
			$lines = explode( "\n", $value );
			foreach ( $lines as &$line ) {
				if ( preg_match( '/^Stock managed by product #(\d+)\.$/D', $line, $match ) ) {
					/* translators: %d: ID of the product managing the stock. */
					$line = sprintf( __( 'Stock managed by product #%d.', 'admin_texts_wc_stock_audittrail' ), (int) $match[1] );
				} elseif ( str_starts_with( $line, 'Receipts: ' ) ) {
					/* translators: %s: Comma-separated Loyverse receipt references. */
					$line = sprintf( __( 'Receipts: %s', 'admin_texts_wc_stock_audittrail' ), substr( $line, strlen( 'Receipts: ' ) ) );
				} elseif ( str_starts_with( $line, 'Receipt: ' ) ) {
					/* translators: %s: Loyverse receipt reference. */
					$line = sprintf( __( 'Receipt: %s', 'admin_texts_wc_stock_audittrail' ), substr( $line, strlen( 'Receipt: ' ) ) );
				}
			}
			unset( $line );
			return implode( '<br>', array_map( 'esc_html', $lines ) );
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
		if ( 'product_name' === $field ) {
			$owner = (int) ( $row->variation_id ?: $row->product_id );
			if ( preg_match( '/^Stock managed by product #(\d+)\./', $row->notes ?? '', $match ) ) {
				$owner = (int) $match[1];
			}
			$period = array_filter( array(
				'from_date' => self::input( $_GET, 'from_date' ),
				'to_date'   => self::input( $_GET, 'to_date' ),
			) );
			return sprintf( '<a href="%s">%s</a>', esc_url( self::url( array( 'stock_history' => $owner ) + $period ) ), esc_html( $value ) );
		}
		return esc_html( (string) $value );
	}

	private function stock_history( int $owner_id ): void {
		$dates   = array_intersect_key( $this->raw_filters(), array( 'from_date' => true, 'to_date' => true ) );
		$filters = $this->filters( $dates, '' ) + array( 'stock_owner' => $owner_id );
		if ( isset( $filters['from'], $filters['until'] ) && $filters['from'] >= $filters['until'] ) {
			wp_die( esc_html__( 'The end date must be on or after the start date.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 400 ) );
		}

		list( $first, $last, $sources ) = Storage::stock_summary( $filters );
		$product = wc_get_product( $owner_id );
		$current = $product && (int) $product->get_stock_managed_by_id() === $owner_id ? $product->get_stock_quantity( 'edit' ) : null;
		/* translators: %d: ID of the product or variation. */
		$title   = $product ? $product->get_name() : sprintf( __( 'Product or variation #%d', 'admin_texts_wc_stock_audittrail' ), $owner_id );

		echo '<div class="wrap">';
		/* translators: %s: Product or variation name. */
		printf( '<h1>%s</h1>', esc_html( sprintf( __( 'Stock history — %s', 'admin_texts_wc_stock_audittrail' ), $title ) ) );
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to Stock Audit Log', 'admin_texts_wc_stock_audittrail' ) );
		/* translators: %d: ID of the stock owner. */
		printf( '<p>%s</p>', esc_html( sprintf( __( 'Stock owner #%d. Shared stock is shown together. Variations with their own stock have separate histories.', 'admin_texts_wc_stock_audittrail' ), $owner_id ) ) );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><p>';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="stock_history" value="%d">', esc_attr( self::LOG ), $owner_id );
		foreach ( array( 'from_date' => __( 'From date', 'admin_texts_wc_stock_audittrail' ), 'to_date' => __( 'Through date', 'admin_texts_wc_stock_audittrail' ) ) as $name => $label ) {
			printf( '<label>%s <input type="date" name="%s" value="%s"></label> ', esc_html( $label ), esc_attr( $name ), esc_attr( $dates[ $name ] ?? '' ) );
		}
		submit_button( __( 'Apply dates', 'admin_texts_wc_stock_audittrail' ), 'secondary', 'filter_action', false );
		printf( ' <a href="%s">%s</a></p></form>', esc_url( self::url( array( 'stock_history' => $owner_id ) ) ), esc_html__( 'All retained history', 'admin_texts_wc_stock_audittrail' ) );

		$groups = array(
			'manual'  => array( 'WP Admin' ),
			'loyverse' => array( 'Loyverse Import', 'Loyverse API' ),
			'orders'  => array( 'WooCommerce Order' ),
		);
		$breakdown = array(
			'manual'  => (object) array( 'label' => __( 'Manual corrections', 'admin_texts_wc_stock_audittrail' ), 'added' => 0, 'removed' => 0 ),
			'loyverse' => (object) array( 'label' => __( 'Loyverse', 'admin_texts_wc_stock_audittrail' ), 'added' => 0, 'removed' => 0 ),
			'orders'  => (object) array( 'label' => __( 'WooCommerce orders', 'admin_texts_wc_stock_audittrail' ), 'added' => 0, 'removed' => 0 ),
			'other'   => (object) array( 'label' => __( 'Other sources', 'admin_texts_wc_stock_audittrail' ), 'added' => 0, 'removed' => 0 ),
		);
		$added = 0;
		$removed = 0;
		$unknown = 0;
		foreach ( $sources as $source ) {
			$label = 'other';
			foreach ( $groups as $group => $members ) {
				if ( in_array( $source->source, $members, true ) ) {
					$label = $group;
					break;
				}
			}
			$breakdown[ $label ]->added   += (float) $source->added;
			$breakdown[ $label ]->removed += (float) $source->removed;
			$added   += (float) $source->added;
			$removed += (float) $source->removed;
			$unknown += (int) $source->unknown;
		}

		$opening = $first->old_stock ?? null;
		$closing = $last->new_stock ?? null;
		$initial_snapshot = null === $opening && isset( $first->new_stock );
		$baseline = $initial_snapshot ? $first->new_stock : $opening;
		$expected = null !== $baseline && (int) $initial_snapshot === $unknown ? round( (float) $baseline + $added - $removed ) : null;
		$live_period = empty( $filters['until'] ) || $filters['until'] > gmdate( 'Y-m-d H:i:s' );
		echo '<h2>' . esc_html__( 'Stock reconciliation', 'admin_texts_wc_stock_audittrail' ) . '</h2>';
		echo '<p>' . esc_html__( 'Opening stock is the quantity before the first retained change in the selected period. Closing stock is the last logged quantity. Current stock is read from WooCommerce.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		echo '<dl class="wsal-balances">';
		foreach ( array(
			array( $initial_snapshot ? __( 'First recorded stock', 'admin_texts_wc_stock_audittrail' ) : __( 'Opening stock', 'admin_texts_wc_stock_audittrail' ), $baseline ),
			array( __( 'Total added', 'admin_texts_wc_stock_audittrail' ), $added ),
			array( __( 'Total removed', 'admin_texts_wc_stock_audittrail' ), $removed ),
			array( __( 'Calculated closing stock', 'admin_texts_wc_stock_audittrail' ), $expected ),
			array( __( 'Last logged closing stock', 'admin_texts_wc_stock_audittrail' ), $closing ),
			array( __( 'Current WooCommerce stock', 'admin_texts_wc_stock_audittrail' ), $current ),
		) as list( $label, $quantity ) ) {
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), null === $quantity ? '&mdash;' : esc_html( number_format_i18n( (float) $quantity ) ) );
		}
		echo '</dl>';

		if ( $last && $live_period && null !== $closing && null !== $current ) {
			$gap = round( (float) $current ) - round( (float) $closing );
			if ( 0.0 === $gap ) {
				echo '<p><strong>' . esc_html__( 'Last logged closing stock matches WooCommerce.', 'admin_texts_wc_stock_audittrail' ) . '</strong></p>';
			} else {
				/* translators: %s: Difference between current and logged stock. */
				printf( '<p><strong>%s</strong></p>', esc_html( sprintf( __( 'Stock discrepancy: current WooCommerce stock minus last logged closing stock is %s.', 'admin_texts_wc_stock_audittrail' ), number_format_i18n( $gap ) ) ) );
			}
		}
		if ( null !== $expected && null !== $closing && 0.0 !== round( (float) $closing - $expected ) ) {
			/* translators: %s: Difference between logged and calculated stock. */
			printf( '<p><strong>%s</strong></p>', esc_html( sprintf( __( 'Audit discrepancy: logged closing stock minus calculated closing stock is %s.', 'admin_texts_wc_stock_audittrail' ), number_format_i18n( (float) $closing - $expected ) ) ) );
		}
		if ( $initial_snapshot ) {
			echo '<p>' . esc_html__( 'The first audit entry establishes the starting quantity. The quantity before that entry is unknown.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		}
		if ( $unknown > (int) $initial_snapshot ) {
			/* translators: %d: Number of changes with an unknown quantity. */
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Changes without two numeric stock values: %d. They are excluded from the added and removed totals.', 'admin_texts_wc_stock_audittrail' ), ( $unknown - (int) $initial_snapshot ) ) ) );
		}
		if ( ! $live_period ) {
			echo '<p>' . esc_html__( 'This period has ended. Current stock can include later changes.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		}

		$this->stock_continuity( $filters );

		echo '<h2>' . esc_html__( 'Changes by source', 'admin_texts_wc_stock_audittrail' ) . '</h2>';
		foreach ( $breakdown as $row ) {
			$row->added   = number_format_i18n( $row->added );
			$row->removed = number_format_i18n( $row->removed );
		}
		$this->render_table( array( 'label' => __( 'Source', 'admin_texts_wc_stock_audittrail' ), 'added' => __( 'Added', 'admin_texts_wc_stock_audittrail' ), 'removed' => __( 'Removed', 'admin_texts_wc_stock_audittrail' ) ), $breakdown );

		echo '<h2>' . esc_html__( 'Individual stock changes', 'admin_texts_wc_stock_audittrail' ) . '</h2>';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-log-table.php';
		$table = new Log_Table( $filters, 'stock_history' );
		$table->prepare_items();
		$table->display();
		echo '</div>';
	}

	private function stock_continuity( array $filters ): void {
		echo '<h2>' . esc_html__( 'Audit continuity', 'admin_texts_wc_stock_audittrail' ) . '</h2>';
		$gap = Storage::first_stock_gap( $filters );
		if ( null === $gap ) {
			echo '<p>' . esc_html__( 'Audit continuity could not be checked. Try again later.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
			return;
		}
		if ( ! $gap ) {
			echo '<p>' . esc_html__( 'No mismatch was found between consecutive entries with known quantities. Changes that left no trace may still be missing.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
			return;
		}

		list( $previous, $next ) = $gap;
		$difference = (float) $next->old_stock - (float) $previous->new_stock;
		echo '<div class="notice notice-warning inline"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: 1: Local date and time of the next entry. 2: Previous closing stock. 3: Next opening stock. 4: Signed quantity difference. */
				__( 'First stock gap at %1$s. Previous closing stock: %2$s. Next opening stock: %3$s. Difference: %4$s.', 'admin_texts_wc_stock_audittrail' ),
				get_date_from_gmt( $next->timestamp, 'Y-m-d H:i:s' ),
				number_format_i18n( (float) $previous->new_stock ),
				number_format_i18n( (float) $next->old_stock ),
				( $difference > 0 ? '+' : '' ) . number_format_i18n( $difference )
			)
		);
		echo '</strong></p><p>';
		printf(
			'<a href="%s">%s</a> | <a href="%s">%s</a>',
			esc_url( self::url( array( 'entry' => $previous->id ) ) ),
			esc_html__( 'Previous entry', 'admin_texts_wc_stock_audittrail' ),
			esc_url( self::url( array( 'entry' => $next->id ) ) ),
			esc_html__( 'Next entry', 'admin_texts_wc_stock_audittrail' )
		);
		echo '</p></div>';
	}

	private function detail( int $id ): void {
		$row = Storage::get( $id );
		if ( ! $row ) {
			wp_die( esc_html__( 'Stock audit entry not found.', 'admin_texts_wc_stock_audittrail' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock audit entry', 'admin_texts_wc_stock_audittrail' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'Back to Stock Audit Log', 'admin_texts_wc_stock_audittrail' ) );
		echo '<table class="widefat striped"><tbody>';
		$fields = self::columns() + array(
			'product_id' => __( 'Product ID', 'admin_texts_wc_stock_audittrail' ), 'variation_id' => __( 'Variation ID', 'admin_texts_wc_stock_audittrail' ), 'user_id' => __( 'User ID', 'admin_texts_wc_stock_audittrail' ),
			'request_uri' => __( 'Request URI', 'admin_texts_wc_stock_audittrail' ), 'ip_address' => __( 'IP address', 'admin_texts_wc_stock_audittrail' ), 'notes' => __( 'Notes', 'admin_texts_wc_stock_audittrail' ),
		);
		foreach ( $fields as $field => $label ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( self::cell( $row, $field ) ) );
		}
		echo '</tbody></table></div>';
	}

	public function product_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'product' !== $post->post_type || ! current_user_can( self::VIEW ) ) {
			return $actions;
		}

		$actions['stock_audit_history'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::url( array( 'stock_history' => $post->ID ) ) ),
			/* translators: %s: Product name. */
			esc_attr( sprintf( __( 'View stock audit history for %s', 'admin_texts_wc_stock_audittrail' ), get_the_title( $post ) ) ),
			esc_html__( 'Stock Audit History', 'admin_texts_wc_stock_audittrail' )
		);
		return $actions;
	}

	public function metabox(): void {
		if ( current_user_can( self::VIEW ) ) {
			add_meta_box( 'stock-audit-history', __( 'Stock Audit History', 'admin_texts_wc_stock_audittrail' ), array( $this, 'product_history' ), 'product' );
		}
	}

	public function product_history( \WP_Post $post ): void {
		$this->authorize( self::VIEW );
		$product = wc_get_product( $post->ID );
		if ( $product ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( self::url( array( 'stock_history' => $product->get_stock_managed_by_id() ) ) ), esc_html__( 'Open stock reconciliation', 'admin_texts_wc_stock_audittrail' ) );
		}
		$columns = array_diff_key( self::columns(), array( 'sku' => true ) );
		$this->render_table( $columns, Storage::rows( array( 'product_id' => $post->ID ), 20 ) );
	}

	public function dashboard(): void {
		if ( current_user_can( self::VIEW ) ) {
			wp_add_dashboard_widget( 'stock_audit_diagnostics', __( 'Stock audit diagnostics', 'admin_texts_wc_stock_audittrail' ), array( $this, 'dashboard_diagnostics' ) );
			wp_add_dashboard_widget( 'stock_audit_latest', __( 'Latest stock mutations', 'admin_texts_wc_stock_audittrail' ), array( $this, 'dashboard_rows' ) );
		}
	}

	public function dashboard_diagnostics(): void {
		$this->authorize( self::VIEW );
		$failures = Storage::failures();
		if ( null === $failures ) {
			echo '<p>' . esc_html__( 'Audit diagnostics are unavailable. Check the WooCommerce logs.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
			return;
		}
		if ( ! $failures ) {
			echo '<p>' . esc_html__( 'No capture failures have been recorded since diagnostics were enabled.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Counts show recorded capture failures by type. A failure may have occurred before WooCommerce completed the stock change.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		echo '<ul>';
		foreach ( $failures as $failure ) {
			$label = match ( $failure->failure_type ) {
				'lock_timeout'    => __( 'Stock lock timed out', 'admin_texts_wc_stock_audittrail' ),
				'lock_error'      => __( 'Stock lock database error', 'admin_texts_wc_stock_audittrail' ),
				'snapshot_error'  => __( 'Stock snapshot failed', 'admin_texts_wc_stock_audittrail' ),
				'insert_error'    => __( 'Audit insert failed', 'admin_texts_wc_stock_audittrail' ),
				'product_missing' => __( 'Stock owner unavailable', 'admin_texts_wc_stock_audittrail' ),
				default           => $failure->failure_type,
			};
			$id = (int) $failure->stock_owner_id;
			$product = wc_get_product( $id );
			/* translators: %d: ID of the product or variation managing stock. */
			$title = $product ? $product->get_name() : sprintf( __( 'Stock owner #%d', 'admin_texts_wc_stock_audittrail' ), $id );
			printf(
				'<li><strong>%s</strong> %s<br>%s <a href="%s">%s</a></li>',
				esc_html( $label ),
				esc_html( number_format_i18n( (float) $failure->occurrences ) ),
				esc_html( get_date_from_gmt( $failure->last_seen, 'Y-m-d H:i:s' ) ),
				esc_url( self::url( array( 'stock_history' => $id ) ) ),
				esc_html( $title )
			);
		}
		echo '</ul>';
		echo '<p>' . esc_html__( 'For individual incidents, see the WooCommerce log source woocommerce-stock-audit-log.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
	}

	public function dashboard_rows(): void {
		$this->authorize( self::VIEW );
		$columns = array_intersect_key( self::columns(), array_flip( array( 'timestamp', 'product_name', 'difference', 'source' ) ) );
		$this->render_table( $columns, Storage::rows( array(), 25 ) );
	}

	private function render_table( array $columns, array $rows ): void {
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No stock mutations found.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
			return;
		}
		echo '<div class="wsal-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Stock audit report', 'admin_texts_wc_stock_audittrail' ) . '"><table class="widefat striped"><thead><tr>';
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
		echo '</tbody></table></div>';
	}

	public function settings(): void {
		$this->authorize( self::MANAGE );
		$this->upgrade_schema();
		echo '<div class="wrap"><h1>' . esc_html__( 'Stock Audit Settings', 'admin_texts_wc_stock_audittrail' ) . '</h1>';
		if ( '1' === self::input( $_GET, 'saved' ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'admin_texts_wc_stock_audittrail' ) . '</p></div>';
		}
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="stock_audit_settings">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'stock_audit_settings' );
		echo '<label>' . esc_html__( 'Retention', 'admin_texts_wc_stock_audittrail' ) . ' <select name="retention_days">';
		$current = Storage::retention();
		foreach ( Storage::RETENTION as $days ) {
			/* translators: %s: Formatted number of days. */
			printf( '<option value="%d"%s>%s</option>', $days, selected( $current, $days, false ), esc_html( $days ? sprintf( _n( '%s day', '%s days', $days, 'admin_texts_wc_stock_audittrail' ), number_format_i18n( $days ) ) : __( 'Unlimited', 'admin_texts_wc_stock_audittrail' ) ) );
		}
		echo '</select></label><p>' . esc_html__( 'Scheduled cleanup permanently removes entries older than the selected retention period.', 'admin_texts_wc_stock_audittrail' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	public function save_settings(): void {
		$this->authorize( self::MANAGE );
		check_admin_referer( 'stock_audit_settings' );
		$days = self::input( $_POST, 'retention_days' );
		if ( ! ctype_digit( $days ) || ! Storage::set_retention( (int) $days ) ) {
			wp_die( esc_html__( 'The retention setting could not be saved.', 'admin_texts_wc_stock_audittrail' ) );
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
			wp_die( esc_html__( 'The CSV stream could not be opened.', 'admin_texts_wc_stock_audittrail' ) );
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
					if ( '' !== $value && in_array( $field, array( 'old_stock', 'new_stock', 'difference' ), true ) ) {
						$value = number_format( (float) $value, 0, '.', '' );
					}
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
