<?php
/**
 * WP-110: POST/GET ts-chat/v1/stock-alerts.
 *
 * @package TS_Chat_API
 */

ts_reset_limits();
ts_product( [ 'id' => 271509, 'in_stock' => false, 'url' => 'https://www.tehranspeaker.com/product/anker-q30/' ] );
ts_product( [ 'id' => 271547, 'parent' => 271509, 'type' => 'variation', 'in_stock' => false, 'url' => 'https://www.tehranspeaker.com/product/anker-q30/?attribute_pa_color=black' ] );
ts_product( [ 'id' => 18247, 'in_stock' => true ] );
ts_product( [ 'id' => 77, 'status' => 'draft' ] );
ts_product( [ 'id' => 88, 'parent' => 18247, 'type' => 'variation' ] );

// احراز هویت پیش از هر چیز.
$noToken = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '09123456789' ], [], null );
ts_same( [ 401, [ 'code' => 'unauthorized' ] ], [ $noToken->status, $noToken->data ], 'missing secret → 401 unauthorized' );
$wrong = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '09123456789' ], [], TS_CHAT_API_SECRET . 'x' );
ts_same( 401, $wrong->status, 'wrong secret → 401' );
ts_same( [], $GLOBALS['ts_t']['contacts'], 'a refused request writes nothing' );
$query = new WP_REST_Request();
$query->params = [ 'token' => TS_CHAT_API_SECRET ];
ts_same( '', TS_Chat_API_Auth::bearer( $query ), 'the secret is never read from the query string' );

// ثبت تازه، تکرار، و گروه همان افزونه‌ی پیامک.
$first = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '۰۹۱۲۳۴۵۶۷۸۹', 'source' => 'chatbot', 'wp_user_id' => 12 ] );
ts_same( [ 201, [ 'status' => 'subscribed', 'product_id' => 271509 ] ], [ $first->status, $first->data ], 'out-of-stock product → 201 subscribed' );
ts_same( 1, count( $GLOBALS['ts_t']['contacts'] ), 'one PWSMS contact row' );
ts_same( '_in', $GLOBALS['ts_t']['contacts'][1]['groups'], 'in the PWSMS back-in-stock group' );
ts_same( '09123456789', $GLOBALS['ts_t']['contacts'][1]['mobile'], 'Persian digits normalised' );
ts_same( [ '9123456789' ], get_post_meta( 271509, '_ts_chat_stock_alert_mobiles', true ), 'chatbot source remembered on the product' );
ts_same( 'no-store', $first->headers['Cache-Control'], 'never cached' );

$again = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '+98 912 345 6789' ] );
ts_same( [ 200, [ 'status' => 'already_subscribed', 'product_id' => 271509 ] ], [ $again->status, $again->data ], 'repeat → 200 already_subscribed' );
ts_same( 1, count( $GLOBALS['ts_t']['contacts'] ), 'no duplicate row' );

// یک مخاطب موجود با گروه دیگر، گروه «موجود شد» را اضافه می‌گیرد.
$GLOBALS['ts_t']['contacts'][2] = [ 'id' => 2, 'product_id' => 271547, 'mobile' => '09350000000', 'groups' => '_onsale' ];
$variation = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'variation_id' => 271547, 'mobile' => '00989350000000' ] );
ts_same( 201, $variation->status, 'variation subscription → 201' );
ts_same( 271509, $variation->data['product_id'], 'answers with the parent id' );
ts_same( '_onsale,_in', $GLOBALS['ts_t']['contacts'][2]['groups'], 'existing contact keeps its groups and gains _in' );

// وضعیت.
$status = ts_request( 'GET', '/ts-chat/v1/stock-alerts/status', null, [ 'product_id' => '271509', 'mobile' => '09123456789' ] );
ts_same( [ 200, [ 'subscribed' => true ] ], [ $status->status, $status->data ], 'status: subscribed' );
$status = ts_request( 'GET', '/ts-chat/v1/stock-alerts/status', null, [ 'product_id' => '271509', 'mobile' => '09120000000' ] );
ts_same( [ 'subscribed' => false ], $status->data, 'status: another mobile is not subscribed (no other subscriber data)' );
ts_same( 401, ts_request( 'GET', '/ts-chat/v1/stock-alerts/status', null, [ 'product_id' => '271509', 'mobile' => '09120000000' ], 'bad' )->status, 'status needs the secret' );

// خطاها.
$cases = [
	'in stock'            => [ [ 'product_id' => 18247, 'mobile' => '09123456780' ], 409, 'in_stock' ],
	'unknown product'     => [ [ 'product_id' => 424242, 'mobile' => '09123456780' ], 404, 'not_found' ],
	'draft product'       => [ [ 'product_id' => 77, 'mobile' => '09123456780' ], 404, 'not_found' ],
	'variation as parent' => [ [ 'product_id' => 271547, 'mobile' => '09123456780' ], 404, 'not_found' ],
	'foreign variation'   => [ [ 'product_id' => 271509, 'variation_id' => 88, 'mobile' => '09123456780' ], 404, 'not_found' ],
	'landline'            => [ [ 'product_id' => 271509, 'mobile' => '02188888888' ], 422, 'invalid_mobile' ],
	'letters'             => [ [ 'product_id' => 271509, 'mobile' => '0912abc4567' ], 422, 'invalid_mobile' ],
	'foreign country'     => [ [ 'product_id' => 271509, 'mobile' => '+4915112345678' ], 422, 'invalid_mobile' ],
	'missing mobile'      => [ [ 'product_id' => 271509 ], 422, 'invalid_mobile' ],
];
foreach ( $cases as $label => [ $body, $code, $error ] ) {
	$response = ts_request( 'POST', '/ts-chat/v1/stock-alerts', $body );
	ts_same( [ $code, [ 'code' => $error ] ], [ $response->status, $response->data ], "{$label} → {$code} {$error}" );
}
$GLOBALS['ts_t']['notif'] = false;
$off = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '09121111111' ] );
ts_same( [ 409, [ 'code' => 'alerts_unavailable' ] ], [ $off->status, $off->data ], 'PWSMS back-in-stock disabled → 409 alerts_unavailable' );
$GLOBALS['ts_t']['notif'] = true;

foreach ( [ '9123456789', '989123456789', '+989123456789', '00989123456789', '0912 345 6789', '٠٩١٢٣٤٥٦٧٨٩' ] as $raw ) {
	ts_same( '09123456789', TS_Chat_API_Stock_Alerts::normalize_mobile( $raw ), "normalise {$raw}" );
}

// محدودیت نرخ: ۵ در ساعت برای هر موبایل.
ts_reset_limits();
$codes = [];
for ( $i = 0; $i < 6; $i++ ) {
	$codes[] = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '09125555555' ] )->status;
}
ts_same( [ 201, 200, 200, 200, 200, 429 ], $codes, 'the 6th request for one mobile within an hour → 429' );
$GLOBALS['ts_t']['now'] += HOUR_IN_SECONDS + 1;
ts_same( 200, ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 271509, 'mobile' => '09125555555' ] )->status, 'the window resets after an hour' );

// ۶۰ در دقیقه در کل.
ts_reset_limits();
$last = null;
for ( $i = 0; $i < 61; $i++ ) {
	$last = ts_request( 'POST', '/ts-chat/v1/stock-alerts', [ 'product_id' => 18247, 'mobile' => sprintf( '0912%07d', $i ) ] );
}
ts_same( [ 429, [ 'code' => 'rate_limited' ] ], [ $last->status, $last->data ], 'the 61st request in a minute overall → 429 rate_limited' );
ts_reset_limits();

// پیامک موجود شدن: مشترک چت‌بات لینک UTM می‌گیرد و PWSMS دوباره به او نمی‌فرستد.
$GLOBALS['ts_t']['contacts'][90] = [ 'id' => 90, 'product_id' => 271509, 'mobile' => '09129999999', 'groups' => '_in' ];
$GLOBALS['ts_t']['products'][271509]->p['in_stock'] = true;
do_action( 'woocommerce_product_set_stock_status', 271509, 'instock', wc_get_product( 271509 ) );
ts_same( 1, count( $GLOBALS['ts_t']['sms'] ), 'one SMS batch for chatbot subscribers' );
$sms = $GLOBALS['ts_t']['sms'][0];
ts_assert( strpos( $sms['message'], 'https://www.tehranspeaker.com/product/anker-q30/?utm_source=chatbot&utm_medium=sms&utm_campaign=stock_alert' ) !== false, 'the product link carries the chatbot UTM' );
ts_assert( in_array( '09123456789', $sms['mobile'], true ) && ! in_array( '09129999999', $sms['mobile'], true ), 'only chatbot-sourced subscribers get the UTM text' );
ts_same( 11, $sms['type'], 'archived as the PWSMS in-stock event' );
ts_same( [ '09129999999' ], \PW\PWSMS\Helper::remove_sent_mobiles( \PW\PWSMS\Subscription\Contacts::get_contacts_mobile( 271509, '_in' ), 11, 271509 ), 'PWSMS still sends its own text to the web-form subscriber, and skips the chatbot one' );

// همان رخداد دوباره چیزی نمی‌فرستد.
do_action( 'woocommerce_product_set_stock_status', 271509, 'instock', wc_get_product( 271509 ) );
ts_same( 1, count( $GLOBALS['ts_t']['sms'] ), 'no second SMS within 24h' );
$GLOBALS['ts_t']['products'][271509]->p['in_stock'] = false;
