<?php
/**
 * WP-117: GET ts-chat/v1/campaigns.
 *
 * @package TS_Chat_API
 */

ts_reset_limits();
$now  = $GLOBALS['ts_t']['now'];
$tz   = new DateTimeZone( 'Asia/Tehran' );
$yesterday = ( new DateTimeImmutable( '@' . ( $now - 86400 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
$later     = ( new DateTimeImmutable( '@' . ( $now + 5 * 86400 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
$day  = 86400;
$base = [
	'status'  => 'publish',
	'created' => $now - 10 * $day,
	'expires' => $now + 9 * $day,
	'type'    => 'percent',
	'amount'  => 15,
];
ts_product( [ 'id' => 271509 ] );
ts_product( [ 'id' => 271547, 'parent' => 271509, 'type' => 'variation' ] );

ts_coupon( array_merge( $base, [ 'id' => 1, 'code' => 'PAEEZ15', 'description' => 'تخفیف هدفون‌های انکر', 'products' => [ 271547, 271509 ], 'categories' => [ 116 ],
	'meta' => [ '_ts_public_campaign' => 'yes', '_ts_public_campaign_code' => 'yes', '_ts_campaign_title' => 'جشنواره پاییزه', '_ts_campaign_slug' => 'autumn-1405', '_ts_campaign_discount_text' => 'تا ۱۵٪', '_ts_campaign_starts_at' => $yesterday ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 2, 'code' => 'HIDDENCODE', 'amount' => 500000, 'type' => 'fixed_cart',
	'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'ارسال رایگان' ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 3, 'code' => 'STAFF', 'meta' => [ '_ts_campaign_title' => 'کارمندان' ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 4, 'code' => 'VIP', 'emails' => [ 'a@example.com' ], 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'VIP' ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 5, 'code' => 'OLD', 'expires' => $now - 1, 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'تمام‌شده' ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 6, 'code' => 'SOON', 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'به‌زودی', '_ts_campaign_starts_at' => $later ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 7, 'code' => 'FOREVER', 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'بی‌پایان' ] ] ) );
unset( $GLOBALS['ts_t']['coupons'][7]['expires'] );
ts_coupon( array_merge( $base, [ 'id' => 8, 'code' => 'ONEOFF', 'limit' => 1, 'used' => 1, 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'یک‌بار مصرف' ] ] ) );
ts_coupon( array_merge( $base, [ 'id' => 9, 'code' => 'DRAFT', 'status' => 'draft', 'meta' => [ '_ts_public_campaign' => 'yes', '_ts_campaign_title' => 'پیش‌نویس' ] ] ) );

$response = ts_request( 'GET', '/ts-chat/v1/campaigns' );
ts_same( 200, $response->status, '200 with the secret' );
ts_same( [ 'autumn-1405', 'coupon-2' ], array_column( $response->data, 'id' ), 'only active, public, unrestricted, unexhausted, dated campaigns' );
ts_same(
	[
		'id'           => 'autumn-1405',
		'title'        => 'جشنواره پاییزه',
		'description'  => 'تخفیف هدفون‌های انکر',
		'discountText' => 'تا ۱۵٪',
		'productIds'   => [ 271509 ],
		'categoryIds'  => [ 116 ],
		'startsAt'     => $yesterday . 'T00:00:00+03:30',
		'endsAt'       => TS_Chat_API_Campaigns::iso( $now + 9 * $day ),
		'publicCode'   => 'PAEEZ15',
	],
	$response->data[0],
	'contract shape, variation folded into its parent, ISO 8601 with timezone'
);
ts_same( '۵۰۰٬۰۰۰ تومان تخفیف', $response->data[1]['discountText'], 'discount text derived from the coupon when marketing left it empty' );
ts_assert( ! array_key_exists( 'publicCode', $response->data[1] ), 'a code marketing did not mark public is never returned' );
$json = json_encode( $response->data, JSON_UNESCAPED_UNICODE );
foreach ( [ 'HIDDENCODE', 'STAFF', 'VIP', 'a@example.com', 'usage', 'ONEOFF' ] as $secret ) {
	ts_assert( strpos( $json, $secret ) === false, "{$secret} never appears in the response" );
}
ts_assert( preg_match( '/^"[0-9a-f]{32}"$/', $response->headers['ETag'] ) === 1, 'an ETag is sent' );
ts_same( $response->headers['ETag'], $response->headers['X-TS-Content-Tag'], 'the tag survives the site stripping ETag' );
ts_same( 'private, max-age=60', $response->headers['Cache-Control'], 'cacheable for a minute by the caller only' );

$cached = ts_request( 'GET', '/ts-chat/v1/campaigns', null, [], TS_CHAT_API_SECRET, [ 'if_none_match' => $response->headers['ETag'] ] );
ts_same( [ 304, null ], [ $cached->status, $cached->data ], 'If-None-Match with the same ETag → 304' );
ts_same( [ 401, [ 'code' => 'unauthorized' ] ], [ ts_request( 'GET', '/ts-chat/v1/campaigns', null, [], 'bad' )->status, ts_request( 'GET', '/ts-chat/v1/campaigns', null, [], 'bad' )->data ], 'bad secret → 401' );

$GLOBALS['ts_t']['now'] = $now + 10 * $day;
$campaigns = new TS_Chat_API_Campaigns();
ts_same( null, $campaigns->campaign( new WC_Coupon( 1 ), time() + 10 * $day ), 'the same campaign is gone after it ends' );
ts_same( null, $campaigns->campaign( new WC_Coupon( 6 ), strtotime( $later . 'T00:00:00+03:30' ) - 3600 ), 'not yet started the evening before' );
ts_assert( $campaigns->campaign( new WC_Coupon( 6 ), strtotime( $later . 'T00:00:01+03:30' ) ) !== null, 'live from the start of its start day, site time' );
$GLOBALS['ts_t']['now'] = $now;

// بدون کلید پیکربندی‌شده هیچ مسیری باز نیست (در زیرفرایند، چون ثابت تعریف شده است).
$probe = 'define("ABSPATH", "/"); class WP_Error { public $c; public $d; public function __construct($c = "", $m = "", $d = null) { $this->c = $c; $this->d = $d; } }'
	. ' require ' . var_export( dirname( __DIR__ ) . '/includes/class-ts-chat-api-auth.php', true ) . ';'
	. ' function add_filter() {}'
	. ' echo json_encode([TS_Chat_API_Auth::is_configured(), TS_Chat_API_Auth::permission(new class { public function get_header($n) { return "Bearer x"; } })->d]);';
$out = shell_exec( 'php -r ' . escapeshellarg( '$_SERVER = []; ' . str_replace( 'require', 'require_once', $probe ) ) . ' 2>&1' );
ts_same( '[false,{"status":503}]', trim( (string) $out ), 'no TS_CHAT_API_SECRET → every route answers 503 and stays closed' );
$short = shell_exec( 'php -r ' . escapeshellarg( 'define("TS_CHAT_API_SECRET", "short"); ' . $probe ) . ' 2>&1' );
ts_same( '[false,{"status":503}]', trim( (string) $short ), 'a secret under 48 characters counts as unset' );
