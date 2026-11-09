<?php
/**
 * Observe persisted stock quantity changes.
 *
 * @package WooCommerceStockAuditLog
 */

namespace StockAuditLog;

defined( 'ABSPATH' ) || exit;

final class Stock_Audit_Log {
	private array $pending = array();
	private array $initialized = array();

	public function register(): void {
		add_action( 'add_post_meta', array( $this, 'before_add' ), PHP_INT_MAX, 3 );
		add_action( 'added_post_meta', array( $this, 'after_add' ), PHP_INT_MIN, 3 );
		add_action( 'update_post_meta', array( $this, 'before_meta' ), PHP_INT_MAX, 3 );
		add_action( 'updated_post_meta', array( $this, 'after_meta' ), PHP_INT_MIN, 3 );
		add_action( 'delete_post_meta', array( $this, 'before_meta' ), PHP_INT_MAX, 3 );
		add_action( 'deleted_post_meta', array( $this, 'after_meta' ), PHP_INT_MIN, 3 );
		add_filter( 'woocommerce_update_product_stock_query', array( $this, 'before_sql' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_updated_product_stock', array( $this, 'after_sql' ), PHP_INT_MIN );
		add_action( 'woocommerce_product_set_stock', array( $this, 'after_product_stock' ), PHP_INT_MIN );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'after_product_stock' ), PHP_INT_MIN );
		add_action( 'shutdown', array( $this, 'release_pending' ) );
	}

	public function before_add( int $id, string $key ): void {
		if ( '_stock' === $key && $this->is_product( $id ) ) {
			$this->begin( 'add', $id );
		}
	}

	public function after_add( int $meta_id, int $id, string $key ): void {
		if ( '_stock' !== $key ) {
			return;
		}

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 18 ) as $frame ) {
			if ( 'update_product_stock' === $frame['function'] && is_a( $frame['class'] ?? '', 'WC_Product_Data_Store_CPT', true ) ) {
				$entry = $this->pop( 'add', $id );
				if ( $entry ) {
					// Keep the lock through the SQL write and treat WooCommerce's seed as part of it.
					$this->initialized[ $id ] = $entry;
				}
				return;
			}
		}
		$this->finish( 'add', $id );
	}

	public function before_meta( $meta_ids, int $id, string $key ): void {
		if ( '_stock' === $key && $id > 0 && $this->is_product( $id ) ) {
			$this->begin( current_filter(), $id );
		}
	}

	public function after_meta( $meta_ids, int $id, string $key ): void {
		if ( '_stock' === $key ) {
			$route = 'updated_post_meta' === current_filter() ? 'update_post_meta' : 'delete_post_meta';
			$this->finish( $route, $id );
		}
	}

	public function before_sql( string $sql, int $id ): string {
		if ( isset( $this->initialized[ $id ] ) ) {
			$this->pending['sql'][ $id ][] = $this->initialized[ $id ];
			unset( $this->initialized[ $id ] );
		} elseif ( $this->is_product( $id ) ) {
			$this->begin( 'sql', $id );
		}
		return $sql;
	}

	public function after_sql( int $id ): void {
		$this->finish( 'sql', $id );
	}

	public function after_product_stock( \WC_Product $product ): void {
		$this->finish( 'sql', $product->get_id() );
	}

	private function is_product( int $id ): bool {
		return in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true );
	}

	private function read_stock( int $id ): ?string {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' ORDER BY meta_id LIMIT 1", $id )
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Stock snapshot query failed.' );
		}
		return is_numeric( $value ) ? (string) $value : null;
	}

	private function begin( string $route, int $id ): void {
		global $wpdb;
		$lock = 'wsal:' . substr( hash( 'sha256', DB_NAME . '/' . $wpdb->postmeta . '/' . $id ), 0, 55 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 2 ) ) ) {
			$this->pending[ $route ][ $id ][] = null;
			$this->warn( 'Could not acquire the stock snapshot lock.', $id );
			return;
		}

		try {
			$this->pending[ $route ][ $id ][] = array( 'lock' => $lock, 'old' => $this->read_stock( $id ) );
		} catch ( \RuntimeException $error ) {
			$this->release( $lock );
			$this->pending[ $route ][ $id ][] = null;
			$this->warn( $error->getMessage(), $id );
		}
	}

	private function pop( string $route, int $id ): ?array {
		if ( empty( $this->pending[ $route ][ $id ] ) ) {
			return null;
		}
		$entry = array_pop( $this->pending[ $route ][ $id ] );
		if ( empty( $this->pending[ $route ][ $id ] ) ) {
			unset( $this->pending[ $route ][ $id ] );
		}
		return $entry;
	}

	private function finish( string $route, int $id ): void {
		$entry = $this->pop( $route, $id );
		if ( ! $entry ) {
			return;
		}

		try {
			$new = $this->read_stock( $id );
			$old = $entry['old'];
			if ( $old === $new || ( null !== $old && null !== $new && (float) $old === (float) $new ) ) {
				return;
			}
			$this->record( $id, $old, $new );
		} catch ( \RuntimeException $error ) {
			$this->warn( $error->getMessage(), $id );
		} finally {
			$this->release( $entry['lock'] );
		}
	}

	private function release( string $lock ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	public function release_pending(): void {
		foreach ( $this->pending as $products ) {
			foreach ( $products as $entries ) {
				foreach ( $entries as $entry ) {
					if ( $entry ) {
						$this->release( $entry['lock'] );
					}
				}
			}
		}
		foreach ( $this->initialized as $entry ) {
			$this->release( $entry['lock'] );
		}
	}

	/**
	 * Recover the initiating line item or order while WooCommerce is still writing.
	 * Only the named core stock functions contribute order context.
	 *
	 * @return array{int, int}
	 */
	private function order_context( int $stock_id ): array {
		$stock_product = null;
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 40 ) as $frame ) {
			if ( isset( $frame['class'] ) ) {
				continue;
			}
			$arg = $frame['args'][0] ?? null;
			switch ( $frame['function'] ) {
				case 'wc_update_product_stock':
					if ( null !== $stock_product || ! $arg instanceof \WC_Product || $stock_id !== (int) $arg->get_stock_managed_by_id() ) {
						return array( 0, 0 );
					}
					$stock_product = $arg;
					break;
				case 'wc_reduce_stock_levels':
				case 'wc_increase_stock_levels':
				case 'wc_restock_refunded_items':
				case 'wc_maybe_adjust_line_item_product_stock':
					if ( ! $stock_product ) {
						break;
					}
					$order_id = $arg instanceof \WC_Order ? $arg->get_id() : absint( is_numeric( $arg ) ? $arg : 0 );
					if ( $arg instanceof \WC_Order_Item_Product ) {
						$order_id = $arg->get_order_id();
					}
					return array( (int) $order_id, $stock_product->is_type( 'variation' ) ? $stock_product->get_id() : 0 );
			}
		}
		return array( 0, $stock_product && $stock_product->is_type( 'variation' ) ? $stock_product->get_id() : 0 );
	}

	/**
	 * Match the local Loyverse importer's stock-plan save and read its receipt references.
	 *
	 * @return string[]|null Receipt references, or null outside the importer.
	 */
	private function loyverse_receipts( int $stock_id ): ?array {
		if ( ! class_exists( 'Loyverse_Sales_In_WooCommerce', false ) ) {
			return null;
		}

		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 40 ) as $frame ) {
			if ( 'Loyverse_Sales_In_WooCommerce' !== ( $frame['class'] ?? '' ) || 'apply_or_recover_stock_plan' !== $frame['function'] ) {
				continue;
			}
			$update  = $frame['args'][0] ?? array();
			$product = $update['product'] ?? null;
			if ( ! $product instanceof \WC_Product || $stock_id !== (int) $product->get_id() ) {
				return null;
			}

			$receipts = array();
			foreach ( (array) ( $update['receipt_numbers'] ?? array() ) as $receipt ) {
				if ( is_scalar( $receipt ) && preg_match( '/^[a-zA-Z0-9_.:\/-]{1,120}$/D', (string) $receipt ) ) {
					$receipts[] = (string) $receipt;
				}
			}
			sort( $receipts, SORT_STRING );
			return array_unique( $receipts );
		}
		return null;
	}

	public static function detect_source( int $order_id = 0 ): string {
		if ( $order_id > 0 ) {
			return 'WooCommerce Order';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$marker = self::server( 'HTTP_X_STOCK_SOURCE' );
			$agent  = self::server( 'HTTP_USER_AGENT' );
			$receipt = self::server( 'HTTP_X_LOYVERSE_RECEIPT' );
			$hint   = 'loyverse' === strtolower( $marker ) || 1 === preg_match( '/\bloyverse\b/i', $agent ) || '' !== $receipt;

			/**
			 * Confirm that the REST identity belongs to the configured integration.
			 *
			 * @param bool $trusted Whether the authenticated identity is trusted.
			 * @param int  $user_id Authenticated WordPress user ID.
			 */
			if ( $hint && get_current_user_id() && apply_filters( 'stock_audit_log_trust_loyverse', false, get_current_user_id() ) ) {
				return 'Loyverse API';
			}
			return 'REST API';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		if ( wp_doing_cron() ) {
			return 'WP Cron';
		}
		return is_admin() && get_current_user_id() ? 'WP Admin' : 'Unknown';
	}

	private static function server( string $key ): string {
		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ?? '' ) );
	}

	private function record( int $stock_id, ?string $old, ?string $new ): void {
		list( $order_id, $variation_id ) = $this->order_context( $stock_id );
		$owner = wc_get_product( $stock_id );
		if ( ! $owner ) {
			$this->warn( 'Could not resolve the product for an observed stock change.', $stock_id );
			return;
		}
		$product = $variation_id && $variation_id !== $stock_id ? wc_get_product( $variation_id ) : $owner;
		$product = $product ?: $owner;
		$variation_id = $product->is_type( 'variation' ) ? $product->get_id() : null;
		$product_id = $variation_id ? $product->get_parent_id() : $stock_id;
		$receipts = $order_id ? null : $this->loyverse_receipts( $stock_id );
		$source   = null !== $receipts ? 'Loyverse Import' : self::detect_source( $order_id );
		$order  = $order_id ? wc_get_order( $order_id ) : false;
		$user   = wp_get_current_user();
		$rest   = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$uri    = $rest ? explode( '?', self::server( 'REQUEST_URI' ), 2 )[0] : '';
		$ip     = $rest ? self::server( 'REMOTE_ADDR' ) : '';
		$notes  = array();

		if ( $product->get_id() !== $stock_id ) {
			$notes[] = sprintf( 'Stock managed by product #%d.', $stock_id );
		}
		if ( $receipts ) {
			$notes[] = 'Receipts: ' . implode( ', ', $receipts );
		}
		if ( 'Loyverse API' === $source ) {
			$receipt = self::server( 'HTTP_X_LOYVERSE_RECEIPT' );
			if ( preg_match( '/^[a-zA-Z0-9_.:\/-]{1,100}$/D', $receipt ) ) {
				$notes[] = 'Receipt: ' . $receipt;
			}
		}

		$old  = null === $old ? null : number_format( (float) $old, 2, '.', '' );
		$new  = null === $new ? null : number_format( (float) $new, 2, '.', '' );
		$diff = null === $old || null === $new ? null : number_format( (float) $new - (float) $old, 2, '.', '' );

		if ( ! Storage::insert(
			array(
				'timestamp'    => gmdate( 'Y-m-d H:i:s' ),
				'product_id'   => $product_id ?: $stock_id,
				'variation_id' => $variation_id,
				'sku'          => $product->get_sku(),
				'product_name' => $product->get_name(),
				'old_stock'    => $old,
				'new_stock'    => $new,
				'difference'   => $diff,
				'source'       => $source,
				'user_id'      => $user->exists() ? $user->ID : null,
				'username'     => $user->exists() ? $user->user_login : null,
				'order_id'     => $order_id ?: null,
				'order_number' => $order ? (string) $order->get_order_number() : null,
				'request_uri'  => $uri ?: null,
				'ip_address'   => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null,
				'notes'        => $notes ? implode( "\n", $notes ) : null,
			)
		) ) {
			$this->warn( 'Could not insert a stock audit entry.', $stock_id );
		}
	}

	private function warn( string $message, int $id ): void {
		wc_get_logger()->error(
			sprintf( '%s Stock owner ID: %d.', $message, $id ),
			array( 'source' => 'woocommerce-stock-audit-log' )
		);
	}
}
