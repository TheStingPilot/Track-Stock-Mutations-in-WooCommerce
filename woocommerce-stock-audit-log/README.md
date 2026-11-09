# WooCommerce Stock Audit Log

WooCommerce Stock Audit Log records stock quantity changes for products and variations. It helps store operators trace changes to orders, administrator edits, REST integrations, scheduled jobs, and scripts.

**Authors** — TheStingPilot and Codex  
**License** — [GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

## Install

1. Use WordPress 6.5 or later, WooCommerce 9.1 or later, PHP 8.1 or later, and a MySQL or MariaDB database that supports advisory locks. Validate your exact versions and integrations on staging before production rollout.
2. Upload the plugin ZIP from **Plugins → Add New → Upload Plugin**, or copy the complete `woocommerce-stock-audit-log` folder to `wp-content/plugins/`.
3. Activate the plugin. On multisite, activate it separately for each store. Activation creates the audit and settings tables with the site's database prefix.
4. Make a test stock edit and check **WooCommerce → Stock Audit Log**. The history begins at activation.
5. Set your retention period under **WooCommerce → Stock Audit Settings**. The default is unlimited.

Deactivation stops the scheduled cleanup and preserves the tables. WordPress deletion also leaves the tables in place. Back up any records you need before removing the tables manually.

## Find a change

**WooCommerce → Stock Audit Log** opens on the latest 500 changes, with 50 entries per page. Apply a filter or search to query the retained history. The end date includes the full day in the site's WordPress timezone.

- Filter by date, product or variation ID, exact SKU, source, user ID, or order number or ID.
- Search SKU, product name, username, or order number. Numeric search terms also match internal order IDs.
- Open an entry by clicking its timestamp. The detail view includes the request path, IP address, and notes when available.
- Open **Stock Audit History** on a product's edit screen for its latest 20 entries, including its variations. The dashboard widget shows the latest 25 entries across the store.
- Use **Top 50 products**, **Manual corrections**, or **Loyverse mutations** to focus the report. The Loyverse report shows the receipt reference when supplied.
- Click **Export CSV** on a mutation list to download all retained entries matching its filters, including entries beyond the first 500. Export timestamps use UTC.

### Example investigations

| Question | Where to look | Example result |
| --- | --- | --- |
| Why did SKU `USB-C-001` drop today? | Enter the SKU and set both dates to today. | At 10:15, stock changed from 10 to 8. Source was WooCommerce Order #12345. |
| Who corrected stock manually? | Open **Manual corrections** and check **User**. | At 10:45, the fictional account `inventory-demo` changed stock from 8 to 15. |
| Which changes came from Loyverse? | Open **Loyverse mutations** and check the receipt column. | At 11:02, stock changed from 15 to 14. Receipt reference was `987654`. |

## Configure Loyverse attribution

Use a dedicated, authenticated WooCommerce REST identity for the integration. Configure the connector to send `X-Stock-Source: Loyverse` and, when available, `X-Loyverse-Receipt`. A recognizable Loyverse user agent or receipt header can also serve as the marker. The plugin labels a marked request **Loyverse API** once the store has trusted its authenticated identity through the filter described in [TECHNICAL.md](TECHNICAL.md#loyverse-trust). Until then, those changes appear under **REST API**.

## Access and retention

Users with `manage_woocommerce` can view and export the audit, including any operational identifiers it stores. Users with `manage_options` can change retention. REST requests can store a query-free request path and the address reported by the web server. Store accounts are identified by WordPress user ID and login name when available. Restrict access to exports and keep them out of public repositories.

The hourly cleanup removes expired rows in batches. Confirm that WP-Cron runs reliably on your store. Select 30, 90, 180, or 365 days to apply automatic expiry, or **Unlimited** to keep all entries.

See [TECHNICAL.md](TECHNICAL.md) for supported write paths, configuration, and a staging checklist.
