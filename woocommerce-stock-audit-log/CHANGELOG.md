# Changelog

## 1.1.0 — 2026-11-10

- Flag the first mismatch between consecutive stock entries for a product or variation, with the time, signed quantity difference, and links to both entries.
- Add a **Stock audit diagnostics** dashboard widget with capture failure counts, the latest occurrence per type, and a link to the affected stock owner.
- Separate lock timeouts from database errors in diagnostics and WooCommerce logs.

## 1.0.1 — 2026-11-09

- Store and display stock quantities as whole units using `DECIMAL(12,0)`.
- Upgrade existing audit tables and recalculate differences from the whole-unit snapshots.

## 1.0.0 — 2026-11-09

- Made the administration interface translatable using WordPress gettext and WPML String Translation.
- Set English as the source language and `admin_texts_wc_stock_audittrail` as the text domain. Added a POT catalog and translation instructions.
- Translated audit source labels and built-in note prefixes at display time while preserving stored values and CSV output.

## 0.0.4

- Added the **Stock Audit History** action to product rows in WooCommerce administration.

## 0.0.3

- Added scrollable tables and product stock reconciliation with source totals and a live WooCommerce stock comparison.

## 0.0.2

- Recognized local Loyverse imports and displayed the importer's verified stock-plan history separately.

## 0.0.1

- Initial release with stock capture, reports, CSV export, and retention.
