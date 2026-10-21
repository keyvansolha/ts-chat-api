<?php
/**
 * کمپین‌های عمومی فعال برای چت‌بات.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * GET /ts-chat/v1/campaigns (WP-117).
 *
 * منبع، کوپن‌های ووکامرس است: بازاریابی در صفحه‌ی ویرایش کوپن تیک «کمپین عمومی
 * چت‌بات» را می‌زند. فقط کوپن منتشرشده، علامت‌خورده، بدون محدودیت ایمیل مشتری،
 * با ظرفیت باقی‌مانده و در بازه‌ی شروع تا انقضا برگردانده می‌شود. کد کوپن فقط
 * وقتی در پاسخ می‌آید که تیک «نمایش کد» هم زده شده باشد. شمار استفاده، ایمیل‌ها
 * و هیچ داده‌ی مشتری‌ای بیرون نمی‌رود.
 */
class TS_Chat_API_Campaigns {

	const META_PUBLIC      = '_ts_public_campaign';
	const META_SHOW_CODE   = '_ts_public_campaign_code';
	const META_TITLE       = '_ts_campaign_title';
	const META_SLUG        = '_ts_campaign_slug';
	const META_STARTS      = '_ts_campaign_starts_at';
	const META_DISCOUNT    = '_ts_campaign_discount_text';

	/** سقف کوپن‌هایی که بررسی می‌شوند. */
	const MAX_COUPONS = 100;

	/**
	 * ثبت مسیر و فیلدهای پیشخوان کوپن.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'woocommerce_coupon_options', [ $this, 'render_fields' ], 20, 2 );
		add_action( 'woocommerce_coupon_options_save', [ $this, 'save_fields' ], 20, 2 );
	}

	/**
	 * مسیر REST.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_CHAT_API_NAMESPACE,
			'/campaigns',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => [ 'TS_Chat_API_Auth', 'permission' ],
			]
		);
	}

	/**
	 * زمان ISO 8601 با منطقه‌ی زمانی سایت.
	 *
	 * @param int $timestamp مهر زمانی یونیکس.
	 */
	public static function iso( int $timestamp ): string {
		$date = new DateTimeImmutable( '@' . $timestamp );

		return $date->setTimezone( wp_timezone() )->format( 'c' );
	}

	/**
	 * شروع کمپین: تاریخ واردشده (ابتدای روز به وقت سایت) یا زمان ساخت کوپن.
	 *
	 * @param WC_Coupon $coupon کوپن.
	 */
	private function starts_at( $coupon ): int {
		$raw = (string) $coupon->get_meta( self::META_STARTS, true );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) === 1 ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, wp_timezone() );
			if ( $date ) {
				return $date->getTimestamp();
			}
		}
		$created = $coupon->get_date_created();

		return $created ? $created->getTimestamp() : 0;
	}

	/**
	 * رقم‌های فارسی.
	 *
	 * @param string $text متن.
	 */
	private static function persian_digits( string $text ): string {
		return strtr( $text, [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ] );
	}

	/**
	 * متن تخفیف: متن بازاریابی، یا ساخته‌شده از نوع و مبلغ خود کوپن.
	 *
	 * @param WC_Coupon $coupon کوپن.
	 */
	private function discount_text( $coupon ): string {
		$text = TS_Chat_API_FAQ::plain( (string) $coupon->get_meta( self::META_DISCOUNT, true ) );
		if ( $text !== '' ) {
			return $text;
		}
		$amount = (float) $coupon->get_amount();
		if ( $amount <= 0 ) {
			return '';
		}
		if ( $coupon->get_discount_type() === 'percent' ) {
			return self::persian_digits( rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) ) . '٪ تخفیف';
		}

		return self::persian_digits( number_format( $amount, 0, '.', '٬' ) ) . ' ' . html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . ' تخفیف';
	}

	/**
	 * یک کوپن به شکل قرارداد، یا null وقتی نباید دیده شود.
	 *
	 * @param WC_Coupon $coupon کوپن.
	 * @param int       $now    اکنون.
	 */
	public function campaign( $coupon, int $now ): ?array {
		if ( ! $coupon || ! $coupon->get_id() || get_post_status( $coupon->get_id() ) !== 'publish' ) {
			return null;
		}
		if ( $coupon->get_meta( self::META_PUBLIC, true ) !== 'yes' ) {
			return null;
		}
		// کوپن مخصوص مشتری‌های مشخص، خصوصی است.
		if ( ! empty( $coupon->get_email_restrictions() ) ) {
			return null;
		}
		$limit = (int) $coupon->get_usage_limit();
		if ( $limit > 0 && (int) $coupon->get_usage_count() >= $limit ) {
			return null;
		}
		$expires = $coupon->get_date_expires();
		$starts  = $this->starts_at( $coupon );
		// کمپین بی‌تاریخ پایان منتشر نمی‌شود: چت‌بات نباید تخفیف بی‌پایان وعده دهد.
		if ( ! $expires || $starts <= 0 || $starts > $now || $now > $expires->getTimestamp() ) {
			return null;
		}

		$slug  = sanitize_title( (string) $coupon->get_meta( self::META_SLUG, true ) );
		$title = TS_Chat_API_FAQ::plain( (string) $coupon->get_meta( self::META_TITLE, true ) );
		$item  = [
			'id'           => $slug !== '' ? $slug : 'coupon-' . (int) $coupon->get_id(),
			'title'        => $title,
			'description'  => TS_Chat_API_FAQ::plain( (string) $coupon->get_description() ),
			'discountText' => $this->discount_text( $coupon ),
		];
		if ( $item['title'] === '' || $item['discountText'] === '' ) {
			return null;
		}

		$products = [];
		foreach ( $coupon->get_product_ids() as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product ) {
				$parent              = (int) $product->get_parent_id();
				$products[ $parent > 0 ? $parent : (int) $product->get_id() ] = true;
			}
		}
		if ( $products !== [] ) {
			$item['productIds'] = array_keys( $products );
		}
		$categories = array_values( array_unique( array_filter( array_map( 'intval', $coupon->get_product_categories() ) ) ) );
		if ( $categories !== [] ) {
			$item['categoryIds'] = $categories;
		}
		$item['startsAt'] = self::iso( $starts );
		$item['endsAt']   = self::iso( $expires->getTimestamp() );
		if ( $coupon->get_meta( self::META_SHOW_CODE, true ) === 'yes' ) {
			$item['publicCode'] = (string) $coupon->get_code();
		}

		return $item;
	}

	/**
	 * کمپین‌های فعال.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public function index( $request ): WP_REST_Response {
		$ids = get_posts(
			[
				'post_type'        => 'shop_coupon',
				'post_status'      => 'publish',
				'meta_key'         => self::META_PUBLIC,
				'meta_value'       => 'yes',
				'fields'           => 'ids',
				'posts_per_page'   => self::MAX_COUPONS,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);

		$now       = time();
		$campaigns = [];
		foreach ( $ids as $id ) {
			$item = $this->campaign( new WC_Coupon( (int) $id ), $now );
			if ( $item !== null ) {
				$campaigns[] = $item;
			}
		}

		$etag     = '"' . md5( (string) wp_json_encode( $campaigns ) ) . '"';
		$match    = (string) $request->get_header( 'if_none_match' );
		$response = new WP_REST_Response( $match === $etag ? null : $campaigns, $match === $etag ? 304 : 200 );
		$response->header( 'ETag', $etag );
		// .htaccess سایت ETag را حذف می‌کند (Header unset ETag)؛ همان برچسب در این هدر هم می‌آید.
		$response->header( 'X-TS-Content-Tag', $etag );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		$response->header( 'Vary', 'Authorization' );

		return $response;
	}

	/**
	 * فیلدهای «کمپین عمومی چت‌بات» در زبانه‌ی عمومی کوپن.
	 *
	 * @param int       $coupon_id شناسه.
	 * @param WC_Coupon $coupon    کوپن.
	 */
	public function render_fields( $coupon_id, $coupon ): void {
		echo '<div class="options_group"><p class="form-field"><strong>کمپین عمومی چت‌بات</strong><br>';
		echo '<span class="description">با این تیک، چت‌بات در پاسخ قیمت این تخفیف را تا تاریخ انقضای کوپن معرفی می‌کند. کوپن بدون تاریخ انقضا، با محدودیت ایمیل یا با ظرفیت تمام‌شده هرگز معرفی نمی‌شود.</span></p>';
		woocommerce_wp_checkbox(
			[
				'id'          => self::META_PUBLIC,
				'label'       => 'کمپین عمومی',
				'description' => 'معرفی این کمپین در چت‌بات',
				'value'       => wc_bool_to_string( $coupon->get_meta( self::META_PUBLIC, true ) === 'yes' ),
			]
		);
		woocommerce_wp_checkbox(
			[
				'id'          => self::META_SHOW_CODE,
				'label'       => 'نمایش کد',
				'description' => 'کد کوپن به مشتری گفته شود',
				'value'       => wc_bool_to_string( $coupon->get_meta( self::META_SHOW_CODE, true ) === 'yes' ),
			]
		);
		woocommerce_wp_text_input(
			[
				'id'    => self::META_TITLE,
				'label' => 'عنوان کمپین',
				'value' => (string) $coupon->get_meta( self::META_TITLE, true ),
			]
		);
		woocommerce_wp_text_input(
			[
				'id'          => self::META_DISCOUNT,
				'label'       => 'متن تخفیف',
				'placeholder' => 'مثلاً: تا ۱۵٪',
				'description' => 'خالی بماند تا از نوع و مبلغ کوپن ساخته شود.',
				'desc_tip'    => true,
				'value'       => (string) $coupon->get_meta( self::META_DISCOUNT, true ),
			]
		);
		woocommerce_wp_text_input(
			[
				'id'          => self::META_SLUG,
				'label'       => 'شناسه‌ی کمپین',
				'placeholder' => 'autumn-1405',
				'description' => 'شناسه‌ی لاتین و ثابت؛ کد کوپن را اینجا ننویسید.',
				'desc_tip'    => true,
				'value'       => (string) $coupon->get_meta( self::META_SLUG, true ),
			]
		);
		woocommerce_wp_text_input(
			[
				'id'                => self::META_STARTS,
				'label'             => 'تاریخ شروع',
				'placeholder'       => 'YYYY-MM-DD',
				'description'       => 'خالی بماند یعنی از زمان ساخت کوپن.',
				'desc_tip'          => true,
				'value'             => (string) $coupon->get_meta( self::META_STARTS, true ),
				'custom_attributes' => [ 'pattern' => '\d{4}-\d{2}-\d{2}' ],
			]
		);
		echo '</div>';
	}

	/**
	 * ذخیره‌ی فیلدهای کمپین.
	 *
	 * @param int       $coupon_id شناسه.
	 * @param WC_Coupon $coupon    کوپن.
	 */
	public function save_fields( $coupon_id, $coupon ): void {
		// ووکامرس nonce فرم کوپن را پیش از این قلاب بررسی کرده است.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$coupon->update_meta_data( self::META_PUBLIC, isset( $_POST[ self::META_PUBLIC ] ) ? 'yes' : 'no' );
		$coupon->update_meta_data( self::META_SHOW_CODE, isset( $_POST[ self::META_SHOW_CODE ] ) ? 'yes' : 'no' );
		$coupon->update_meta_data( self::META_TITLE, sanitize_text_field( wp_unslash( $_POST[ self::META_TITLE ] ?? '' ) ) );
		$coupon->update_meta_data( self::META_DISCOUNT, sanitize_text_field( wp_unslash( $_POST[ self::META_DISCOUNT ] ?? '' ) ) );
		$coupon->update_meta_data( self::META_SLUG, sanitize_title( wp_unslash( $_POST[ self::META_SLUG ] ?? '' ) ) );
		$starts = sanitize_text_field( wp_unslash( $_POST[ self::META_STARTS ] ?? '' ) );
		$coupon->update_meta_data( self::META_STARTS, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $starts ) === 1 ? $starts : '' );
		// phpcs:enable
		$coupon->save_meta_data();
	}
}
