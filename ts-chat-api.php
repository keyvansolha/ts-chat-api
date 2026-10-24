<?php
/**
 * Plugin Name: TehranSpeaker Chatbot API
 * Description: مسیرهای ts-chat/v1 برای چت‌بات (خبرم کن، سؤال متداول پیشنهادی، کمپین‌های عمومی با یک کلید مشترک سمت سرور؛ اقلام همراه محصول و خواندن Store API از دامنه‌ی چت به‌صورت عمومی).
 * Version: 1.1.0
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
require_once __DIR__ . '/includes/class-ts-chat-api-companions.php';
require_once __DIR__ . '/includes/class-ts-chat-api-store-cors.php';

/**
 * راه‌اندازی افزونه پس از بارگذاری همه‌ی افزونه‌ها.
 *
 * مسیرهای نوشتنی و داده‌ی غیرعمومی بدون کلید مشترک بسته می‌مانند. فقط اقلام همراه
 * محصول و خواندن محصولات Store API از دامنه‌ی چت عمومی‌اند؛ هر دو داده‌ی کاتالوگ‌اند.
 */
function ts_chat_api_boot(): void {
	( new TS_Chat_API_Stock_Alerts() )->register();
	( new TS_Chat_API_FAQ() )->register();
	( new TS_Chat_API_Campaigns() )->register();
	( new TS_Chat_API_Companions() )->register();
	( new TS_Chat_API_Store_CORS() )->register();
}
add_action( 'plugins_loaded', 'ts_chat_api_boot', 20 );
