<?php
/**
 * Plugin Name: TehranSpeaker Chatbot API
 * Description: مسیرهای ts-chat/v1 برای چت‌بات (خبرم کن، سؤال متداول پیشنهادی، کمپین‌های عمومی) با یک کلید مشترک سمت سرور.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Parsa Dana, Keyvan Havestin
 * Text Domain: ts-chat-api
 */

defined( 'ABSPATH' ) || exit;

define( 'TS_CHAT_API_FILE', __FILE__ );
define( 'TS_CHAT_API_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );
define( 'TS_CHAT_API_NAMESPACE', 'ts-chat/v1' );

require_once __DIR__ . '/includes/class-ts-chat-api-auth.php';
require_once __DIR__ . '/includes/class-ts-chat-api-rate-limit.php';
require_once __DIR__ . '/includes/class-ts-chat-api-stock-alerts.php';
require_once __DIR__ . '/includes/class-ts-chat-api-faq.php';
require_once __DIR__ . '/includes/class-ts-chat-api-campaigns.php';

/**
 * راه‌اندازی افزونه پس از بارگذاری همه‌ی افزونه‌ها.
 *
 * هر مسیر بدون کلید مشترک بسته می‌ماند؛ نصب افزونه به‌تنهایی چیزی را باز نمی‌کند.
 */
function ts_chat_api_boot(): void {
	( new TS_Chat_API_Stock_Alerts() )->register();
	( new TS_Chat_API_FAQ() )->register();
	( new TS_Chat_API_Campaigns() )->register();
}
add_action( 'plugins_loaded', 'ts_chat_api_boot', 20 );
