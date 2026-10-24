<?php
/**
 * خواندن محصولات Store API ووکامرس از دامنه‌ی چت.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-127: ویجت چت (https://chat.tehranspeaker.com) ورییشن‌های زنده‌ی محصول را
 * مستقیم از Store API می‌خواند.
 *
 * Store API فقط برای مبدأهایی که `is_allowed_http_origin()` بپذیرد
 * `Access-Control-Allow-Origin` می‌فرستد. فیلتر `allowed_http_origin` سراسری است
 * (admin-ajax و wp-login هم آن را می‌پرسند)، پس مبدأ چت فقط وقتی پذیرفته می‌شود
 * که همان درخواست REST در حال پاسخ، یک GET/HEAD به مسیرهای خواندنی محصول باشد:
 *
 *   GET /wc/store/v1/products/{id}
 *   GET /wc/store/v1/products?parent={id}&type=variation  (و هر فهرست محصول دیگر)
 *
 * سبد خرید، تسویه و هر روش نوشتنی برای مبدأ چت بسته می‌ماند و هیچ wildcard اضافه
 * نمی‌شود. مسیر ts-chat/v1/products/{id}/companions مسیر Store API نیست و هدر CORS
 * را خود وردپرس (rest_send_cors_headers) می‌فرستد.
 */
class TS_Chat_API_Store_CORS {

	/** مبدأ پیش‌فرض ویجت چت. */
	const DEFAULT_ORIGIN = 'https://chat.tehranspeaker.com';

	/** مسیرهای خواندنی Store API که به مبدأ چت باز می‌شوند. */
	const ROUTES = [
		'#^/wc/store/v1/products/?$#',
		'#^/wc/store/v1/products/\d+/?$#',
	];

	/**
	 * روش و مسیر درخواست REST در حال پاسخ.
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private static $current = null;

	/**
	 * ثبت فیلترها.
	 */
	public function register(): void {
		// پیش از Store API (اولویت ۱۰) که هدرهای CORS را می‌فرستد.
		add_filter( 'rest_pre_serve_request', [ $this, 'remember_request' ], 1, 4 );
		add_filter( 'allowed_http_origin', [ $this, 'allow_chat_origin' ], 10, 2 );
	}

	/**
	 * مبدأ چت، نرمال‌شده؛ رشته‌ی خالی وقتی مقدار پیکربندی‌شده نامعتبر است.
	 */
	public static function chat_origin(): string {
		$origin = function_exists( 'ts_chat_iframe_origin' ) ? ts_chat_iframe_origin() : self::DEFAULT_ORIGIN;
		$origin = rtrim( trim( (string) apply_filters( 'ts_chat_api_chat_origin', $origin ) ), '/' );

		return preg_match( '#^https://[a-z0-9.-]+(:\d{1,5})?$#i', $origin ) === 1 ? strtolower( $origin ) : '';
	}

	/**
	 * آیا این درخواست، با این مبدأ، باید هدر Allow-Origin بگیرد؟
	 *
	 * @param string $method روش HTTP.
	 * @param string $route  مسیر REST، مثل /wc/store/v1/products/281821.
	 * @param string $origin مبدأ درخواست.
	 */
	public static function allows( string $method, string $route, string $origin ): bool {
		$chat = self::chat_origin();
		if ( $chat === '' || strtolower( rtrim( $origin, '/' ) ) !== $chat ) {
			return false;
		}
		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}
		foreach ( self::ROUTES as $pattern ) {
			if ( preg_match( $pattern, $route ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * درخواست REST را برای فیلتر مبدأ نگه می‌دارد؛ پاسخ را تغییر نمی‌دهد.
	 *
	 * @param bool            $served  آیا پاسخ فرستاده شده.
	 * @param mixed           $result  نتیجه.
	 * @param WP_REST_Request $request درخواست.
	 * @param mixed           $server  سرور REST.
	 */
	public function remember_request( $served, $result = null, $request = null, $server = null ) {
		self::$current = is_object( $request ) && method_exists( $request, 'get_route' )
			? [ (string) $request->get_method(), (string) $request->get_route() ]
			: null;

		return $served;
	}

	/**
	 * فیلتر `allowed_http_origin`: فقط برای درخواست REST ثبت‌شده و مسیر مجاز.
	 *
	 * @param string|false $allowed مبدأ پذیرفته‌شده تا اینجا.
	 * @param string       $origin  مبدأ درخواست.
	 *
	 * @return string|false
	 */
	public function allow_chat_origin( $allowed, $origin = '' ) {
		if ( $allowed || self::$current === null ) {
			return $allowed;
		}

		return self::allows( self::$current[0], self::$current[1], (string) $origin ) ? (string) $origin : $allowed;
	}

	/**
	 * فقط برای تست: پاک کردن درخواست ثبت‌شده.
	 */
	public static function forget(): void {
		self::$current = null;
	}
}
