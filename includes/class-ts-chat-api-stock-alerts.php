<?php
/**
 * ثبت «موجود شد خبرم کن» از طرف چت‌بات در خبرنامه‌ی پیامکی ووکامرس فارسی.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

use PW\PWSMS\Enums\EventsEnum;
use PW\PWSMS\Subscription\Contacts;

/**
 * POST /ts-chat/v1/stock-alerts و GET /ts-chat/v1/stock-alerts/status (WP-110).
 *
 * مشترک در همان جدول و همان گروه `_in` افزونه‌ی «ووکامرس فارسی پیامک» ثبت
 * می‌شود که فرم عمومی محصول در آن می‌نویسد؛ جدول دومی ساخته نمی‌شود. فقط
 * منبع «چت‌بات» در متای همان محصول نگه داشته می‌شود تا پیامک موجود شدن برای
 * این مشترک‌ها لینک دارای UTM داشته باشد و ثبت‌نام‌ها شمرده شوند.
 */
class TS_Chat_API_Stock_Alerts {

	/** گروه «موجود شد» در PWSMS. */
	const GROUP = '_in';

	/** متای محصول (یا تنوع) که موبایل‌های ثبت‌شده از چت‌بات را نگه می‌دارد. */
	const SOURCE_META = '_ts_chat_stock_alert_mobiles';

	/** سقف درخواست برای هر موبایل در ساعت. */
	const PER_MOBILE_LIMIT = 5;

	/** سقف کل درخواست‌ها در دقیقه. */
	const GLOBAL_LIMIT = 60;

	/** پارامترهای UTM لینک پیامک برای مشترک‌های چت‌بات. */
	const UTM = 'utm_source=chatbot&utm_medium=sms&utm_campaign=stock_alert';

	/**
	 * ثبت مسیرها و قلاب ارسال پیامک.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// پیش از PWSMS (اولویت ۱۲) تا آن افزونه همین شماره‌ها را تکراری بداند.
		add_action( 'woocommerce_product_set_stock_status', [ $this, 'notify_chatbot_subscribers' ], 11, 3 );
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'notify_chatbot_subscribers' ], 11, 3 );
	}

	/**
	 * مسیرهای REST.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_CHAT_API_NAMESPACE,
			'/stock-alerts',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'subscribe' ],
				'permission_callback' => [ 'TS_Chat_API_Auth', 'permission' ],
			]
		);
		register_rest_route(
			TS_CHAT_API_NAMESPACE,
			'/stock-alerts/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'status' ],
				'permission_callback' => [ 'TS_Chat_API_Auth', 'permission' ],
			]
		);
	}

	/**
	 * تبدیل موبایل ایرانی به شکل `09xxxxxxxxx`؛ رقم فارسی/عربی و پیشوند
	 * +98، 0098 و 98 پذیرفته می‌شود. هر چیز دیگری رشته‌ی خالی است.
	 *
	 * @param mixed $raw ورودی.
	 */
	public static function normalize_mobile( $raw ): string {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return '';
		}
		$mobile = strtr(
			trim( (string) $raw ),
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
		if ( preg_match( '/^\+?[\d\s\-()]+$/', $mobile ) !== 1 ) {
			return '';
		}
		$plus   = substr( $mobile, 0, 1 ) === '+';
		$digits = (string) preg_replace( '/\D/', '', $mobile );

		if ( $plus && strpos( $digits, '98' ) === 0 ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( $plus ) {
			return '';
		} elseif ( strpos( $digits, '0098' ) === 0 ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
			$digits = '0' . substr( $digits, 2 );
		} elseif ( strpos( $digits, '9' ) === 0 && strlen( $digits ) === 10 ) {
			$digits = '0' . $digits;
		}

		return preg_match( '/^09\d{9}$/', $digits ) === 1 ? $digits : '';
	}

	/**
	 * آیا PWSMS بارگذاری شده است؟
	 */
	private function pwsms_ready(): bool {
		return function_exists( 'PWSMS' ) && class_exists( Contacts::class );
	}

	/**
	 * محصولی که اشتراک روی آن ثبت می‌شود، یا کد خطای قرارداد.
	 *
	 * @param mixed $product_id   شناسه‌ی محصول مادر.
	 * @param mixed $variation_id شناسه‌ی تنوع (اختیاری).
	 *
	 * @return array{target: WC_Product, parent: WC_Product}|string
	 */
	private function resolve( $product_id, $variation_id ) {
		$product_id = is_numeric( $product_id ) ? (int) $product_id : 0;
		if ( $product_id <= 0 ) {
			return 'not_found';
		}
		$parent = wc_get_product( $product_id );
		if ( ! $parent || $parent->get_status() !== 'publish' || $parent->get_parent_id() > 0 ) {
			return 'not_found';
		}
		if ( $variation_id === null || $variation_id === '' || $variation_id === 0 || $variation_id === '0' ) {
			return [ 'target' => $parent, 'parent' => $parent ];
		}
		$variation_id = is_numeric( $variation_id ) ? (int) $variation_id : 0;
		$variation    = $variation_id > 0 ? wc_get_product( $variation_id ) : null;
		// تنوع غیرفعال در پیشخوان وضعیت «private» دارد و مثل تنوع ناموجود رد می‌شود.
		if ( ! $variation || (int) $variation->get_parent_id() !== $product_id || $variation->get_status() !== 'publish' ) {
			return 'not_found';
		}

		return [ 'target' => $variation, 'parent' => $parent ];
	}

	/**
	 * آیا مشترک همین محصول/تنوع در گروه «موجود شد» است؟
	 *
	 * @param int    $target_id شناسه‌ی محصول یا تنوع.
	 * @param string $mobile    موبایل نرمال‌شده.
	 */
	private function contact( int $target_id, string $mobile ): array {
		$contact = (array) Contacts::get_contact_by_mobile( $target_id, $mobile );
		$groups  = ! empty( $contact['groups'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $contact['groups'] ) ) ) : [];

		return [
			'id'         => ! empty( $contact['id'] ) ? (int) $contact['id'] : 0,
			'groups'     => array_values( $groups ),
			'subscribed' => in_array( self::GROUP, $groups, true ),
		];
	}

	/**
	 * پاسخ موفق بدون کش.
	 *
	 * @param array $data   بدنه.
	 * @param int   $status وضعیت.
	 */
	private function ok( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * ثبت اشتراک.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public function subscribe( $request ): WP_REST_Response {
		if ( ! TS_Chat_API_Rate_Limit::hit( 'stock-alerts:all', self::GLOBAL_LIMIT, MINUTE_IN_SECONDS ) ) {
			return TS_Chat_API_Auth::error( 'rate_limited', 429 );
		}

		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : [];
		$mobile = self::normalize_mobile( $body['mobile'] ?? '' );
		if ( $mobile === '' ) {
			return TS_Chat_API_Auth::error( 'invalid_mobile', 422 );
		}
		if ( ! TS_Chat_API_Rate_Limit::hit( 'stock-alerts:mobile:' . $mobile, self::PER_MOBILE_LIMIT, HOUR_IN_SECONDS ) ) {
			return TS_Chat_API_Auth::error( 'rate_limited', 429 );
		}

		$resolved = $this->resolve( $body['product_id'] ?? null, $body['variation_id'] ?? null );
		if ( ! is_array( $resolved ) ) {
			return TS_Chat_API_Auth::error( $resolved, 404 );
		}
		$target    = $resolved['target'];
		$parent    = $resolved['parent'];
		$parent_id = (int) $parent->get_id();
		$target_id = (int) $target->get_id();

		if ( $target->is_in_stock() && $target->is_purchasable() ) {
			return TS_Chat_API_Auth::error( 'in_stock', 409 );
		}
		if ( ! $this->pwsms_ready() || ! PWSMS()->has_notif_condition( 'enable_notif_no_stock', $parent_id ) ) {
			return TS_Chat_API_Auth::error( 'alerts_unavailable', 409 );
		}

		$contact = $this->contact( $target_id, $mobile );
		if ( $contact['subscribed'] ) {
			$this->remember_source( $target_id, $mobile );

			return $this->ok( [ 'status' => 'already_subscribed', 'product_id' => $parent_id ], 200 );
		}

		// همان دو تابعی که مسیر عمومی pwsms/notification/subscribe صدا می‌زند.
		$saved = $contact['id'] > 0
			? Contacts::update_contact(
				[
					'id'         => $contact['id'],
					'product_id' => $target_id,
					'mobile'     => $mobile,
					'groups'     => array_merge( $contact['groups'], [ self::GROUP ] ),
				]
			)
			: Contacts::insert_contact(
				[
					'product_id' => $target_id,
					'mobile'     => $mobile,
					'groups'     => [ self::GROUP ],
				]
			);
		if ( $saved === false ) {
			return TS_Chat_API_Auth::error( 'error', 500 );
		}

		$this->remember_source( $target_id, $mobile );
		update_option( 'ts_chat_api_stock_alert_signups', (int) get_option( 'ts_chat_api_stock_alert_signups', 0 ) + 1, false );

		return $this->ok( [ 'status' => 'subscribed', 'product_id' => $parent_id ], 201 );
	}

	/**
	 * وضعیت اشتراک یک موبایل برای یک محصول.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public function status( $request ): WP_REST_Response {
		if ( ! TS_Chat_API_Rate_Limit::hit( 'stock-alerts:all', self::GLOBAL_LIMIT, MINUTE_IN_SECONDS ) ) {
			return TS_Chat_API_Auth::error( 'rate_limited', 429 );
		}
		$mobile = self::normalize_mobile( $request->get_param( 'mobile' ) ?? '' );
		if ( $mobile === '' ) {
			return TS_Chat_API_Auth::error( 'invalid_mobile', 422 );
		}
		$resolved = $this->resolve( $request->get_param( 'product_id' ), $request->get_param( 'variation_id' ) );
		if ( ! is_array( $resolved ) ) {
			return TS_Chat_API_Auth::error( $resolved, 404 );
		}
		if ( ! $this->pwsms_ready() ) {
			return TS_Chat_API_Auth::error( 'alerts_unavailable', 409 );
		}

		return $this->ok( [ 'subscribed' => $this->contact( (int) $resolved['target']->get_id(), $mobile )['subscribed'] ], 200 );
	}

	/**
	 * منبع «چت‌بات» را کنار همان محصول نگه می‌دارد (فقط ۱۰ رقم آخر موبایل).
	 *
	 * @param int    $target_id شناسه‌ی محصول یا تنوع.
	 * @param string $mobile    موبایل نرمال‌شده.
	 */
	private function remember_source( int $target_id, string $mobile ): void {
		$mobiles = get_post_meta( $target_id, self::SOURCE_META, true );
		$mobiles = is_array( $mobiles ) ? $mobiles : [];
		$key     = substr( $mobile, -10 );
		if ( ! in_array( $key, $mobiles, true ) ) {
			$mobiles[] = $key;
			update_post_meta( $target_id, self::SOURCE_META, $mobiles );
		}
	}

	/**
	 * مشترک‌های چت‌بات یک محصول یا تنوع.
	 *
	 * @param int $target_id شناسه.
	 *
	 * @return string[] ده رقم آخر موبایل‌ها.
	 */
	private function chatbot_mobiles( int $target_id ): array {
		$mobiles = get_post_meta( $target_id, self::SOURCE_META, true );

		return is_array( $mobiles ) ? array_values( array_filter( array_map( 'strval', $mobiles ) ) ) : [];
	}

	/**
	 * پیامک «موجود شد» برای مشترک‌های چت‌بات، با همان متن PWSMS و لینک UTM.
	 *
	 * درست پیش از PWSMS اجرا می‌شود و همان شرط‌های آن را بررسی می‌کند. ارسال با
	 * نوع رویداد و شناسه‌ی همان محصول در بایگانی PWSMS ثبت می‌شود، پس PWSMS
	 * همین شماره‌ها را «ارسال‌شده در ۲۴ ساعت اخیر» می‌داند و دوباره نمی‌فرستد؛
	 * بقیه‌ی مشترک‌ها پیامک معمول خودشان را از PWSMS می‌گیرند.
	 *
	 * @param int        $product_id   محصول یا تنوعی که وضعیتش عوض شد.
	 * @param string     $stock_status وضعیت جدید.
	 * @param WC_Product $product      شیء محصول.
	 */
	public function notify_chatbot_subscribers( $product_id, $stock_status, $product ): void {
		if ( ! $this->pwsms_ready() || ! is_object( $product ) || ! $product->is_in_stock() || $product->is_type( 'variable' ) ) {
			return;
		}
		$product_id = (int) $product_id;
		$parent_id  = $product->get_parent_id() ? (int) $product->get_parent_id() : $product_id;
		$parent     = wc_get_product( $parent_id );
		if ( ! $parent ) {
			return;
		}
		$history = $parent->get_meta( '_in_stock_send', true );
		if ( is_array( $history ) && ( $history[ $product_id ] ?? '' ) === 'yes' ) {
			return;
		}
		if ( ! PWSMS()->has_notif_condition( 'enable_notif_no_stock', $parent_id ) ) {
			return;
		}

		$chatbot = array_unique( array_merge( $this->chatbot_mobiles( $parent_id ), $this->chatbot_mobiles( $product_id ) ) );
		if ( $chatbot === [] ) {
			return;
		}
		$receivers = array_merge(
			Contacts::get_contacts_mobile( $parent_id, self::GROUP ),
			Contacts::get_contacts_mobile( $product_id, self::GROUP )
		);
		$receivers = array_values(
			array_unique(
				array_filter(
					$receivers,
					static function ( $mobile ) use ( $chatbot ) {
						return in_array( substr( (string) preg_replace( '/\D/', '', (string) $mobile ), -10 ), $chatbot, true );
					}
				)
			)
		);
		$receivers = \PW\PWSMS\Helper::remove_sent_mobiles( $receivers, EventsEnum::NEWSLETTER_IN_STOCK_AUTOMATIC, $product_id );
		if ( empty( $receivers ) ) {
			return;
		}

		$message = (string) PWSMS()->replace_tags( 'notif_no_stock_sms', $product_id, $parent_id );
		$url     = rawurldecode( rawurldecode( (string) $product->get_permalink() ) );
		if ( $url !== '' && strpos( $message, $url ) !== false ) {
			$message = str_replace( $url, $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . self::UTM, $message );
		}
		if ( trim( $message ) === '' ) {
			return;
		}

		$sent = PWSMS()->send_sms(
			[
				'post_id' => $product_id,
				'type'    => EventsEnum::NEWSLETTER_IN_STOCK_AUTOMATIC,
				'mobile'  => array_values( $receivers ),
				'message' => $message,
			]
		);

		if ( $sent === true && PWSMS()->get_option( 'notif_no_stock_remove_contacts' ) ) {
			\PW\PWSMS\Helper::remove_contacts_group( array_values( $receivers ), self::GROUP, $product_id );
		}
	}
}
