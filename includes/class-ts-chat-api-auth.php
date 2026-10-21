<?php
/**
 * احراز هویت مسیرهای ts-chat/v1 با کلید مشترک سمت سرور.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلید `TS_CHAT_API_SECRET` در wp-config.php تعریف می‌شود و فقط در هدر
 * `Authorization: Bearer …` پذیرفته می‌شود؛ هرگز از رشته‌ی پرس‌وجو.
 */
class TS_Chat_API_Auth {

	/** کمترین طول قابل‌قبول کلید. */
	const MIN_SECRET_LENGTH = 48;

	/**
	 * کلید پیکربندی‌شده، یا رشته‌ی خالی وقتی تعریف نشده یا کوتاه است.
	 */
	public static function secret(): string {
		$secret = defined( 'TS_CHAT_API_SECRET' ) ? (string) TS_CHAT_API_SECRET : '';

		return strlen( $secret ) >= self::MIN_SECRET_LENGTH ? $secret : '';
	}

	/**
	 * آیا کلید روی این سایت پیکربندی شده است؟
	 */
	public static function is_configured(): bool {
		return self::secret() !== '';
	}

	/**
	 * توکن Bearer درخواست؛ هدر Authorization در برخی پیکربندی‌های Apache به
	 * متغیر REDIRECT_HTTP_AUTHORIZATION منتقل می‌شود.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public static function bearer( $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( $header === '' && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $match ) !== 1 ) {
			return '';
		}

		return $match[1];
	}

	/**
	 * بررسی دسترسی پیش از هر کار دیگر: پیکربندی نشده = 503، کلید نادرست = 401.
	 *
	 * مقایسه در زمان ثابت انجام می‌شود و پیش از هر پرس‌وجو، لاگ یا شمارش.
	 *
	 * @param WP_REST_Request $request درخواست.
	 *
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$secret = self::secret();
		if ( $secret === '' ) {
			return new WP_Error( 'unavailable', 'unavailable', [ 'status' => 503 ] );
		}

		$token = self::bearer( $request );
		if ( $token === '' || ! hash_equals( $secret, $token ) ) {
			return new WP_Error( 'unauthorized', 'unauthorized', [ 'status' => 401 ] );
		}

		return true;
	}

	/**
	 * پاسخ خطای قرارداد: فقط `{ "code": "…" }`، بدون جزئیات داخلی.
	 *
	 * @param string $code   کد خطا.
	 * @param int    $status وضعیت HTTP.
	 */
	public static function error( string $code, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( [ 'code' => $code ], $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}

/**
 * خطاهای مسیرهای ts-chat/v1 به شکل قرارداد `{ "code": "…" }` برگردانده می‌شوند،
 * نه به شکل پیش‌فرض وردپرس که پیام و داده‌ی اضافه دارد.
 *
 * @param mixed           $response پاسخ آماده.
 * @param mixed           $handler  مسیر.
 * @param WP_REST_Request $request  درخواست.
 *
 * @return mixed
 */
function ts_chat_api_contract_errors( $response, $handler, $request ) {
	if ( ! is_wp_error( $response ) || strpos( (string) $request->get_route(), '/' . TS_CHAT_API_NAMESPACE . '/' ) !== 0 ) {
		return $response;
	}
	$data   = $response->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	$code   = (string) $response->get_error_code();
	if ( $code === 'rest_invalid_param' || $code === 'rest_missing_callback_param' ) {
		$code = 'invalid';
	}

	return TS_Chat_API_Auth::error( $code, $status );
}
add_filter( 'rest_request_after_callbacks', 'ts_chat_api_contract_errors', 10, 3 );
