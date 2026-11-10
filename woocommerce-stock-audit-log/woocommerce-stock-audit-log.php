<?php
/**
 * Plugin Name: WooCommerce Stock Audit Log
 * Description: Search, report on, and export WooCommerce stock quantity changes.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 9.1
 * Author: TheStingPilot and Codex
 * License: GPL-2.0-or-later
 * Text Domain: admin_texts_wc_stock_audittrail
 * Domain Path: /languages
 *
 * @package WooCommerceStockAuditLog
 */

namespace StockAuditLog;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-stock-audit-log.php';

register_activation_hook( __FILE__, array( Storage::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Storage::class, 'deactivate' ) );
add_action( Storage::CLEANUP_HOOK, array( Storage::class, 'cleanup' ) );
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'admin_texts_wc_stock_audittrail', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.1', '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce Stock Audit Log requires WooCommerce 9.1 or newer.', 'admin_texts_wc_stock_audittrail' ) . '</p></div>';
					}
				}
			);
			return;
		}

		( new Stock_Audit_Log() )->register();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-admin.php';
			( new Admin() )->register();
		}
	}
);
