<?php
/**
 * WP-126: GET ts-chat/v1/products/{id}/companions.
 *
 * @package TS_Chat_API
 */

$route = '/ts-chat/v1/products/(?P<id>\d+)/companions';
$args  = $GLOBALS['ts_t']['routes'][ $route ] ?? null;
ts_assert( $args !== null, 'the companions route is registered' );
ts_same( '__return_true', $args['permission_callback'], 'public: no shared secret, the widget reads it from the browser' );

$GLOBALS['ts_t']['images'][ 9001 ]['woocommerce_thumbnail'] = 'https://www.tehranspeaker.com/wp-content/uploads/2024/10/bag-200x200.jpg';
$GLOBALS['ts_t']['images'][ 9002 ]['woocommerce_thumbnail'] = 'http://www.tehranspeaker.com/wp-content/uploads/mic-200x200.webp';

// PartyBox 330 with its bag, as the shop would configure it in «اقلام همراه».
ts_product( [ 'id' => 281821, 'type' => 'variable', 'in_stock' => true, 'name' => 'اسپیکر پارتی باکس جی بی ال مدل 330' ] );
ts_product( [ 'id' => 291846, 'type' => 'variable', 'in_stock' => true, 'name' => 'کیف اسپیکر پارتی باکس جی بی ال مدل 330', 'min_price' => '4300000', 'price' => '9800000', 'image' => 9001 ] );
ts_product( [ 'id' => 291900, 'type' => 'simple', 'in_stock' => false, 'name' => 'کابل &amp; آداپتور', 'price' => '350000.4' ] );
ts_product( [ 'id' => 291901, 'type' => 'variable', 'in_stock' => true, 'name' => 'میکروفون <b>بی‌سیم</b>', 'min_price' => '', 'image' => 9002 ] );
ts_product( [ 'id' => 291902, 'type' => 'simple', 'in_stock' => true, 'name' => 'پیش‌نویس', 'status' => 'draft', 'price' => '1' ] );
ts_product( [ 'id' => 291903, 'type' => 'simple', 'in_stock' => true, 'name' => 'بند', 'price' => '120000' ] );
ts_product( [ 'id' => 291904, 'type' => 'simple', 'in_stock' => true, 'name' => 'روکش', 'price' => '90000' ] );
ts_product( [ 'id' => 281847, 'type' => 'variation', 'parent' => 281821, 'in_stock' => true ] );
ts_product( [ 'id' => 300500, 'type' => 'simple', 'in_stock' => true, 'name' => 'بدون همراه' ] );
ts_product( [ 'id' => 300501, 'type' => 'simple', 'status' => 'draft' ] );

// Stored exactly as the theme's admin tab writes it (attrs and a custom title included).
update_post_meta( 281821, 'wbsIncludedProducts', [
	[ 'product' => [ 'id' => '291900' ], 'title' => 'کابل' ],
	[ 'product' => [ 'id' => '291846', 'attrs' => [ 'pa_color' => '0' ] ], 'title' => 'کیف' ],
	[ 'product' => [ 'id' => '291902' ] ],
	[ 'product' => [ 'id' => '291846' ] ],
	[ 'product' => [ 'id' => '281821' ] ],
	[ 'product' => [ 'id' => '424242' ] ],
	[ 'product' => [ 'id' => '' ] ],
	'garbage',
	[ 'product' => [ 'id' => '291901' ] ],
] );

$response = ts_request( 'GET', $route, null, [ 'id' => '281821' ], null );
ts_same( 200, $response->status, '200 without any secret' );
ts_same( 'public, max-age=60', $response->headers['Cache-Control'] ?? null, 'short public cache' );
ts_same(
	[ 'items' => [
		[ 'productId' => 291846, 'name' => 'کیف اسپیکر پارتی باکس جی بی ال مدل 330', 'image' => 'https://www.tehranspeaker.com/wp-content/uploads/2024/10/bag-200x200.jpg', 'price' => 4300000, 'inStock' => true, 'isVariable' => true ],
		[ 'productId' => 291901, 'name' => 'میکروفون بی‌سیم', 'image' => 'https://www.tehranspeaker.com/wp-content/uploads/mic-200x200.webp', 'price' => null, 'inStock' => true, 'isVariable' => true ],
		[ 'productId' => 291900, 'name' => 'کابل & آداپتور', 'image' => null, 'price' => 350000, 'inStock' => false, 'isVariable' => false ],
	] ],
	$response->data,
	'bag first (in stock, lowest variation price), sold-out after, draft/self/missing/duplicate dropped, https only'
);
ts_same( '{"items":[{"productId":291846,', substr( json_encode( $response->data ), 0, 30 ), 'ids, prices and stock are JSON numbers/booleans' );

// Up to 3: in-stock items keep the shop's order and push sold-out ones out.
update_post_meta( 281821, 'wbsIncludedProducts', [
	[ 'product' => [ 'id' => 291900 ] ],
	[ 'product' => [ 'id' => 291903 ] ],
	[ 'product' => [ 'id' => 291846 ] ],
	[ 'product' => [ 'id' => 291904 ] ],
	[ 'product' => [ 'id' => 291901 ] ],
] );
$response = ts_request( 'GET', $route, null, [ 'id' => 281821 ], null );
ts_same( [ 291903, 291846, 291904 ], array_column( $response->data['items'], 'productId' ), 'at most 3, in stock first, shop order kept' );

$response = ts_request( 'GET', $route, null, [ 'id' => 300500 ], null );
ts_same( [ 200, [ 'items' => [] ] ], [ $response->status, $response->data ], 'a product without «اقلام همراه» answers an empty list' );

foreach ( [ 'unknown' => 424242, 'draft' => 300501, 'variation' => 281847 ] as $label => $id ) {
	$response = ts_request( 'GET', $route, null, [ 'id' => $id ], null );
	ts_same( [ 404, [ 'code' => 'not_found' ] ], [ $response->status, $response->data ], "{$label} product → 404 not_found" );
}

ts_same( false, call_user_func( $args['args']['id']['validate_callback'], '0' ), 'id 0 is rejected' );
ts_same( true, call_user_func( $args['args']['id']['validate_callback'], '281821' ), 'a numeric id is accepted' );
