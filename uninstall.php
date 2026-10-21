<?php
/**
 * حذف داده‌های خود افزونه هنگام پاک کردن آن.
 *
 * ثابت TS_CHAT_API_SECRET در wp-config.php دست نمی‌خورد. سؤال‌های پیشنهادی
 * تأییدشده هم حذف نمی‌شوند؛ محتوای ویراستاری‌شده‌ی فروشگاه است.
 *
 * @package TS_Chat_API
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ts_chat_api_stock_alert_signups' );

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ts_chat_api_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ts_chat_api_rl_' ) . '%'
	)
);
