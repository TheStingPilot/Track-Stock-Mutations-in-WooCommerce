<?php
/**
 * Audit storage, queries and retention.
 *
 * @package WooCommerceStockAuditLog
 */

namespace StockAuditLog;

defined( 'ABSPATH' ) || exit;

final class Storage {
	const CLEANUP_HOOK = 'stock_audit_log_cleanup';
	const RETENTION    = array( 0, 30, 90, 180, 365 );
	const SOURCES      = array( 'WooCommerce Order', 'Loyverse API', 'Loyverse Import', 'REST API', 'WP Admin', 'WP Cron', 'WP-CLI', 'Unknown' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stock_audit_log';
	}

	private static function settings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stock_audit_settings';
	}

	public static function activate( bool $network_wide ): void {
		global $wpdb;
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Stock Audit Log individually on each site.', 'admin_texts_wc_stock_audittrail' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = file_get_contents( dirname( __DIR__ ) . '/sql/schema.sql' );
		if ( false === $sql ) {
			wp_die( esc_html__( 'The Stock Audit Log schema file could not be read.', 'admin_texts_wc_stock_audittrail' ) );
		}

		dbDelta(
			strtr(
				$sql,
				array(
					'{{audit_table}}'     => self::table(),
					'{{settings_table}}' => self::settings_table(),
					'{{collation}}'      => $wpdb->get_charset_collate(),
				)
			)
		);

		foreach ( array( self::table(), self::settings_table() ) as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				wp_die( esc_html__( 'The Stock Audit Log tables could not be created.', 'admin_texts_wc_stock_audittrail' ) );
			}
		}

		if ( ! self::upgrade_quantities() ) {
			wp_die( esc_html__( 'The whole-stock schema upgrade could not be completed. Check the database and try again.', 'admin_texts_wc_stock_audittrail' ) );
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Upgrade existing installations without adding work to stock writes.
	 */
	public static function upgrade_quantities(): bool {
		global $wpdb;
		$settings = self::settings_table();
		$key      = 'whole_stock_schema';
		$check    = $wpdb->prepare( 'SELECT setting_value FROM %i WHERE setting_key = %s', $settings, $key );
		if ( '1' === (string) $wpdb->get_var( $check ) ) {
			return true;
		}

		$lock = 'wsal-schema:' . substr( hash( 'sha256', DB_NAME . '/' . self::table() ), 0, 48 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) ) ) {
			return false;
		}

		try {
			if ( '1' === (string) $wpdb->get_var( $check ) ) {
				return true;
			}
			return self::migrate_quantity_columns() && false !== $wpdb->replace(
				$settings,
				array( 'setting_key' => $key, 'setting_value' => 1 ),
				array( '%s', '%d' )
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function migrate_quantity_columns(): bool {
		global $wpdb;
		$table   = self::table();
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), OBJECT_K );
		foreach ( array( 'old_stock', 'new_stock', 'difference' ) as $column ) {
			if ( ! isset( $columns[ $column ] ) ) {
				return false;
			}
		}

		if ( 'decimal(12,0)' !== $columns['old_stock']->Type || 'decimal(12,0)' !== $columns['new_stock']->Type || 'decimal(12,0)' !== $columns['difference']->Type ) {
			$altered = $wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i MODIFY old_stock decimal(12,0) DEFAULT NULL,
						MODIFY new_stock decimal(12,0) DEFAULT NULL, MODIFY difference decimal(12,0) DEFAULT NULL',
					$table
				)
			);
			if ( false === $altered ) {
				return false;
			}
		}

		// Independently rounding the old delta can disagree with the rounded snapshots.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET difference = new_stock - old_stock WHERE NOT (difference <=> (new_stock - old_stock))',
				$table
			)
		);
		return false !== $updated;
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public static function insert( array $row ): bool {
		global $wpdb;
		return false !== $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public static function retention(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT setting_value FROM %i WHERE setting_key = %s', self::settings_table(), 'retention_days' ) );
	}

	public static function set_retention( int $days ): bool {
		global $wpdb;
		if ( ! in_array( $days, self::RETENTION, true ) ) {
			return false;
		}
		return false !== $wpdb->replace( self::settings_table(), array( 'setting_key' => 'retention_days', 'setting_value' => $days ), array( '%s', '%d' ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		$days = self::retention();
		if ( $days < 1 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		for ( $batch = 0; $batch < 10; ++$batch ) {
			$deleted = $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE timestamp < %s ORDER BY timestamp, id LIMIT 1000', self::table(), $cutoff )
			);
			if ( 1000 !== $deleted ) {
				break;
			}
		}
	}

	/**
	 * Build predicates from sanitized filter values. SQL fragments are fixed.
	 *
	 * @return array{string, array}
	 */
	private static function where( array $filters, int $before_id = 0 ): array {
		global $wpdb;
		$parts = array();
		$args  = array();

		foreach ( array( 'sku', 'source', 'user_id' ) as $field ) {
			if ( ! isset( $filters[ $field ] ) || '' === $filters[ $field ] ) {
				continue;
			}
			$parts[] = '%i = %s';
			$args[]  = $field;
			$args[]  = $filters[ $field ];
		}

		if ( ! empty( $filters['loyverse_only'] ) ) {
			$parts[] = 'source IN (%s, %s)';
			array_push( $args, 'Loyverse API', 'Loyverse Import' );
		}

		if ( isset( $filters['order_number'] ) && '' !== $filters['order_number'] ) {
			$number  = ltrim( $filters['order_number'], '#' );
			$parts[] = ctype_digit( $number ) ? '(order_number = %s OR order_id = %d)' : 'order_number = %s';
			$args[]  = $number;
			if ( ctype_digit( $number ) ) {
				$args[] = (int) $number;
			}
		}

		if ( ! empty( $filters['product_id'] ) ) {
			$parts[] = '(product_id = %d OR variation_id = %d)';
			$args[]  = $filters['product_id'];
			$args[]  = $filters['product_id'];
		}

		if ( ! empty( $filters['stock_owner'] ) ) {
			$owner = $filters['stock_owner'];
			$parts[] = '((variation_id = %d AND (notes IS NULL OR notes NOT LIKE %s))
				OR (product_id = %d AND (variation_id IS NULL OR notes LIKE %s)))';
			array_push( $args, $owner, 'Stock managed by product #%', $owner, "Stock managed by product #{$owner}.%" );
		}

		foreach ( array( 'from', 'until' ) as $boundary ) {
			if ( ! empty( $filters[ $boundary ] ) ) {
				$parts[] = 'from' === $boundary ? 'timestamp >= %s' : 'timestamp < %s';
				$args[]  = $filters[ $boundary ];
			}
		}

		if ( ! empty( $filters['s'] ) || '0' === ( $filters['s'] ?? '' ) ) {
			$term = $filters['s'];
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$search = 'sku LIKE %s OR product_name LIKE %s OR username LIKE %s OR order_number LIKE %s';
			array_push( $args, $like, $like, $like, '%' . $wpdb->esc_like( ltrim( $term, '#' ) ) . '%' );
			if ( ctype_digit( ltrim( $term, '#' ) ) ) {
				$search .= ' OR order_id = %d';
				$args[] = (int) ltrim( $term, '#' );
			}
			$parts[] = '(' . $search . ')';
		}

		if ( $before_id > 0 ) {
			$parts[] = 'id < %d';
			$args[]  = $before_id;
		}

		return array( $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '', $args );
	}

	public static function rows( array $filters, int $limit, int $offset = 0, int $before_id = 0, bool $export = false ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $filters, $before_id );
		$order = $export ? 'id DESC' : 'timestamp DESC, id DESC';
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( array( self::table() ), $args, array( $limit, $offset ) ) )
		) ?? array();
	}

	/**
	 * Aggregate both directions independently across the full selected history.
	 */
	public static function stock_summary( array $filters ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $filters );
		$args = array_merge( array( self::table() ), $args );
		$first = $wpdb->get_row(
			$wpdb->prepare( "SELECT old_stock, new_stock FROM %i{$where} ORDER BY timestamp, id LIMIT 1", $args )
		);
		$last = $wpdb->get_row(
			$wpdb->prepare( "SELECT new_stock FROM %i{$where} ORDER BY timestamp DESC, id DESC LIMIT 1", $args )
		);
		$totals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, SUM(GREATEST(COALESCE(difference, 0), 0)) AS added,
					SUM(GREATEST(-COALESCE(difference, 0), 0)) AS removed,
					COUNT(*) - COUNT(difference) AS unknown
				FROM %i{$where} GROUP BY source",
				$args
			)
		);
		return array( $first, $last, $totals ?? array() );
	}

	public static function count( array $filters ): int {
		global $wpdb;
		list( $where, $args ) = self::where( $filters );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i{$where}", array_merge( array( self::table() ), $args ) )
		);
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
	}

	/**
	 * Read retained, verified stock plans from the local Loyverse importer.
	 */
	public static function loyverse_history( int $before_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'vslvw_stock_plans';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}

		$cursor = $before_id ? ' AND id < %d' : '';
		$args   = array( $table, 'VERIFIED' );
		if ( $before_id ) {
			$args[] = $before_id;
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, updated_at, sku, expected_old_stock, planned_new_stock, stock_delta, receipt_numbers
				FROM %i WHERE status = %s AND actual_stock = planned_new_stock AND stock_delta <> 0{$cursor}
				ORDER BY id DESC LIMIT 51",
				$args
			)
		) ?? array();
	}

	public static function top_products( array $filters ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $filters );
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT latest.product_id, latest.variation_id, latest.sku, latest.product_name, grouped.mutations
				FROM (SELECT COUNT(*) AS mutations, MAX(id) AS last_id FROM %i{$where}
					GROUP BY product_id, variation_id ORDER BY mutations DESC, last_id DESC LIMIT 50) AS grouped
				INNER JOIN %i AS latest ON latest.id = grouped.last_id
				ORDER BY grouped.mutations DESC, latest.id DESC",
				array_merge( array( $table ), $args, array( $table ) )
			)
		) ?? array();
	}
}
