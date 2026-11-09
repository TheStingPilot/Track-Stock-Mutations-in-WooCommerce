# Technical documentation

## Layout

| File | Responsibility |
| --- | --- |
| `woocommerce-stock-audit-log.php` | Bootstrap, dependency check, activation, and HPOS declaration |
| `includes/class-stock-audit-log.php` | Mutation capture, snapshots, and source attribution |
| `includes/class-storage.php` | Database setup, reads, writes, filters, and retention |
| `includes/class-admin.php` | Administration, product history, reports, and CSV |
| `includes/class-log-table.php` | Paged mutation list |
| `sql/schema.sql` | Database definitions used by activation |

## Database

Activation loads the schema with `dbDelta()`. For a manual SQL installation, substitute the site's prefixed table names for `{{audit_table}}` and `{{settings_table}}`, and substitute its character set and collation clause for `{{collation}}`. The standard prefix results in `wp_stock_audit_log` and `wp_stock_audit_settings`. Activate the plugin to schedule cleanup.

The audit table records the product identity at the time of the change. A variation entry uses the parent ID in `product_id` and the variation ID in `variation_id`. If a known variation uses its parent's stock, the values refer to the parent's stock and the notes identify the stock owner. If the writer supplies only the parent, the entry identifies the parent. A missing quantity is SQL `NULL`. The difference is available when both quantities exist. Quantity fields use two decimal places.

Indexes cover timestamps, product and variation IDs, SKU, source, order ID, order number, and user ID. The overview orders by UTC timestamp and ID. Substring search can scan rows, so combine it with indexed filters for wide histories. Exports page by descending ID and hold at most 500 rows per batch. The export can reflect retention deletions made while it is running. The cleanup job deletes up to 10,000 expired rows per invocation.

## Write hooks

| Hook | Use |
| --- | --- |
| `add_post_meta` and `added_post_meta` | Capture initial product or variation `_stock` writes. Fold WooCommerce's temporary zero seed into the direct write that follows it. |
| `update_post_meta` and `updated_post_meta` | Capture stock changes made through the WordPress Metadata API, including ordinary WooCommerce product and variation saves. |
| `delete_post_meta` and `deleted_post_meta` | Capture removal of the saved quantity for an individual product or variation. |
| `woocommerce_update_product_stock_query` | Take a snapshot immediately before WooCommerce's direct stock SQL and return the original query. |
| `woocommerce_updated_product_stock` | Read and record the persisted result after the direct update. |
| `woocommerce_product_set_stock` and `woocommerce_variation_set_stock` | Finalize a pending direct write if the data store emits only the product or variation completion hook. Metadata updates already have their own completion hooks. |

On the direct stock path, the recorder looks at a bounded PHP call stack for the initiating `wc_update_product_stock()` call and WooCommerce's order stock functions `wc_reduce_stock_levels()`, `wc_increase_stock_levels()`, `wc_restock_refunded_items()`, and `wc_maybe_adjust_line_item_product_stock()`. This lets it record the order ID and, when available, the initiating variation in the same insert. The order-level completion hooks fire after item writes and therefore do not insert additional records. Call-stack arguments are used in memory for attribution and are never included in the audit. Order reads and edit links use WooCommerce's order API for HPOS compatibility.

Each observed write uses a short, per-stock-owner MySQL or MariaDB advisory lock and uncached before/after snapshots. Use a single primary connection for the stock reads, write, audit insert, and lock calls. Validate database drop-ins, replica routing, and integrations that introduce additional writes in the same callback. Writes that bypass the observed hooks, bulk deletes across all products, and checkout reservations outside the persisted stock quantity are outside this coverage.

An unchanged quantity produces no row. A stock write and its audit insert are separate operations. A lock timeout, database failure, or terminated request can leave a gap. The plugin allows normal WooCommerce processing to continue and logs detected capture errors under the WooCommerce log source `woocommerce-stock-audit-log`. Monitor that source if audit completeness is operationally important.

## Source precedence

1. **WooCommerce Order** when a supported order stock function supplies an order ID.
2. **Loyverse API** for a marked and trusted authenticated REST request.
3. **REST API** for other REST requests.
4. **WP-CLI** when `WP_CLI` is true.
5. **WP Cron** when `wp_doing_cron()` is true.
6. **WP Admin** for an authenticated WordPress admin request.
7. **Unknown** for other callers, including standalone scripts without recognized context.

### Loyverse trust

Add a site-specific filter to a private integration plugin or MU-plugin. The snippet below assumes the site defines `STORE_LOYVERSE_USER_ID` in its private configuration with the ID of the dedicated WooCommerce REST account. The filter runs only for authenticated REST requests that carry a Loyverse marker.

```php
add_filter(
    'stock_audit_log_trust_loyverse',
    static function ( bool $trusted, int $user_id ): bool {
        return $trusted || (
            defined( 'STORE_LOYVERSE_USER_ID' )
            && $user_id === (int) STORE_LOYVERSE_USER_ID
        );
    },
    10,
    2
);
```

Use the address supplied by the web server for request attribution. Configure proxy handling at the web server if needed. The plugin excludes query strings from stored paths and does not store raw request headers or credentials.

## Staging checklist

- Activate and verify both tables with the store's prefix. Deactivate and reactivate to verify history survives and cleanup reschedules.
- Change simple product stock in the admin and through the REST API. Save the same quantity again and verify that the entry count stays unchanged.
- Change a variation with its own stock. Place an order for a variation that shares its parent's stock. Verify the correct owner quantity, initiating variation, order ID, and delta.
- Test order deduction, cancellation, line-item adjustments, and refunds with and without restocking. Repeat with HPOS on and off.
- Run stock adjustments through WP-CLI and WP-Cron. Send a marked REST request using the trusted account and repeat with an untrusted account.
- Run two stock increments at once against the same product and confirm the entries form a contiguous sequence. Simulate an audit insert error and inspect the WooCommerce error log.
- Test the reports and a filtered CSV with more than 100,000 representative entries. Measure query times, check execution plans, and verify CSV row counts.
- Verify role access and that cleanup removes expired entries while retaining newer entries.

## Validation for 0.0.1

The plugin was exercised locally with WordPress 6.9.4, WooCommerce 10.7.0, PHP 8.3.31, and MariaDB 11.4.5. Checks covered activation with a non-default table prefix, simple and variation stock, no-op saves, order deduction and restoration, refunds and order edits with HPOS enabled and disabled, WP Admin and Cron attribution, marked REST requests with and without Loyverse trust, and two concurrent stock increments. The admin views rendered and a filtered CSV was parsed. A 200,000-row synthetic dataset exercised filtered counting, the top-products query, CSV batch reads, and bounded retention cleanup.

Loyverse identification was tested with simulated REST headers and a configured trust filter. Validate the real connector on staging before deployment.

## Upstream references

- [WordPress Metadata API implementation](https://developer.wordpress.org/reference/files/wp-includes/meta.php/)
- [WooCommerce stock functions](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html)
- [WooCommerce product data store](https://woocommerce.github.io/code-reference/classes/WC-Product-Data-Store-CPT.html)
- [WooCommerce HPOS compatibility](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/)
