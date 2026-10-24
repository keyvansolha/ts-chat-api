<?php
/**
 * اقلام همراه یک محصول برای چت‌بات.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * GET /ts-chat/v1/products/{id}/companions (WP-126).
 *
 * منبع، تب «اقلام همراه» در صفحه‌ی ویرایش محصول است که قالب amazing می‌سازد
 * (lib/Product/functions/product-tabs.php): متای `wbsIncludedProducts` روی خود
 * محصول، آرایه‌ای مرتب از `{ product: { id, attrs? }, title? }` به همان ترتیبی
 * که در پیشخوان کشیده شده است. همین متا در صفحه‌ی محصول جعبه‌ی «اقلام همراه»
 * را می‌سازد؛ این مسیر فقط آن را بی‌تغییر می‌خواند.
 *
 * برخلاف بقیه‌ی مسیرهای این افزونه، این مسیر عمومی و بدون کلید است: ویجت چت
 * آن را مستقیم از مرورگر می‌خواند و پاسخ چیزی جز داده‌ی عمومی کاتالوگ ندارد.
 */
class TS_Chat_API_Companions {

	const META = 'wbsIncludedProducts';

	/** بیشترین تعداد اقلامی که برگردانده می‌شود. */
	const MAX_ITEMS = 3;

	/**
	 * ثبت مسیر.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * مسیر REST.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_CHAT_API_NAMESPACE,
			'/products/(?P<id>\d+)/companions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'show' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					],
				],
			]
		);
	}

	/**
	 * محصول منتشرشده‌ی اصلی (نه ورییشن)، یا null.
	 *
	 * @param int $id شناسه.
	 */
	private static function published_product( int $id ) {
		$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $product || $product->get_status() !== 'publish' || $product->is_type( 'variation' ) ) {
			return null;
		}

		return $product;
	}

	/**
	 * قیمت به تومانِ صحیح؛ برای محصول متغیر کمترین قیمت ورییشن‌ها. null وقتی قیمت ندارد.
	 *
	 * @param WC_Product $product محصول.
	 */
	private static function price( $product ): ?int {
		$raw = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min' ) : $product->get_price();
		if ( $raw === '' || $raw === null || ! is_numeric( $raw ) ) {
			return null;
		}

		return (int) round( (float) $raw );
	}

	/**
	 * نشانی https بندانگشتی، یا null.
	 *
	 * @param WC_Product $product محصول.
	 */
	private static function image( $product ): ?string {
		$id  = (int) $product->get_image_id();
		$url = $id > 0 ? wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ) : false;
		if ( ! is_string( $url ) || $url === '' ) {
			return null;
		}
		$url = (string) preg_replace( '#^http://#i', 'https://', $url );

		return stripos( $url, 'https://' ) === 0 ? $url : null;
	}

	/**
	 * اقلام همراه محصول به شکل قرارداد: موجودها اول (ترتیب فروشگاه حفظ می‌شود)، حداکثر سه.
	 *
	 * قلم پاک‌شده، منتشرنشده، تکراری یا خودِ محصول کنار گذاشته می‌شود.
	 *
	 * @param int $product_id شناسه‌ی محصول.
	 *
	 * @return array<int, array{productId: int, name: string, image: ?string, price: ?int, inStock: bool, isVariable: bool}>
	 */
	public static function items( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META, true );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$seen     = [ $product_id => true ];
		$in_stock = [];
		$sold_out = [];
		foreach ( $stored as $entry ) {
			$id = is_array( $entry ) && isset( $entry['product'] ) && is_array( $entry['product'] ) ? (int) ( $entry['product']['id'] ?? 0 ) : 0;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = self::published_product( $id );
			if ( ! $product ) {
				continue;
			}

			$row = [
				'productId'  => $id,
				'name'       => html_entity_decode( wp_strip_all_tags( (string) $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'image'      => self::image( $product ),
				'price'      => self::price( $product ),
				'inStock'    => (bool) $product->is_in_stock(),
				'isVariable' => $product->is_type( 'variable' ),
			];
			if ( $row['inStock'] ) {
				$in_stock[] = $row;
			} else {
				$sold_out[] = $row;
			}
		}

		return array_slice( array_merge( $in_stock, $sold_out ), 0, self::MAX_ITEMS );
	}

	/**
	 * پاسخ مسیر.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public function show( $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'id' );
		if ( ! self::published_product( $product_id ) ) {
			return TS_Chat_API_Auth::error( 'not_found', 404 );
		}

		$response = new WP_REST_Response( [ 'items' => self::items( $product_id ) ], 200 );
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}
}
