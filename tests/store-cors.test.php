<?php
/**
 * WP-127: the chat origin may read Store API products, nothing else.
 *
 * @package TS_Chat_API
 */

$chat  = 'https://chat.tehranspeaker.com';
$cors  = new TS_Chat_API_Store_CORS();
$serve = static function ( string $method, string $route ) use ( $cors ) {
	$request         = new WP_REST_Request();
	$request->method = $method;
	$request->route  = $route;
	return apply_filters( 'rest_pre_serve_request', false, null, $request, null );
};
$origin_for = static function ( string $origin ) {
	return apply_filters( 'allowed_http_origin', false, $origin );
};

ts_same( $chat, TS_Chat_API_Store_CORS::chat_origin(), 'default chat origin' );
ts_assert( isset( $GLOBALS['ts_t']['filters']['rest_pre_serve_request'] ), 'request is remembered before Store API sends CORS' );

// Before any REST request (admin-ajax, wp-login): nothing is opened.
TS_Chat_API_Store_CORS::forget();
ts_same( false, $origin_for( $chat ), 'outside a REST request the chat origin is not allowed' );

ts_same( false, $serve( 'GET', '/wc/store/v1/products/281821' ), 'rest_pre_serve_request passes $served through' );
ts_same( $chat, $origin_for( $chat ), 'GET product by id → chat origin allowed' );
ts_same( false, $origin_for( 'https://evil.example' ), 'another origin is not' );
ts_same( false, $origin_for( 'https://chat.tehranspeaker.com.evil.example' ), 'a look-alike origin is not' );
ts_same( false, $origin_for( 'http://chat.tehranspeaker.com' ), 'plain http is not' );
ts_same( false, $origin_for( '*' ), 'no wildcard' );

$serve( 'HEAD', '/wc/store/v1/products/281821' );
ts_same( $chat, $origin_for( $chat ), 'HEAD (curl -I) is read-only too' );

$serve( 'GET', '/wc/store/v1/products' );
ts_same( $chat, $origin_for( $chat ), 'GET products?parent=…&type=variation (collection) allowed' );

foreach ( [
	[ 'GET', '/wc/store/v1/cart' ],
	[ 'POST', '/wc/store/v1/cart/add-item' ],
	[ 'GET', '/wc/store/v1/cart/items' ],
	[ 'POST', '/wc/store/v1/checkout' ],
	[ 'GET', '/wc/store/v1/order/123' ],
	[ 'POST', '/wc/store/v1/products/281821' ],
	[ 'DELETE', '/wc/store/v1/products/281821' ],
	[ 'GET', '/wc/store/v1/products/281821/../../cart' ],
	[ 'GET', '/wc/store/v1/products/attributes' ],
	[ 'GET', '/wc/v3/products/281821' ],
] as [ $method, $route ] ) {
	$serve( $method, $route );
	ts_same( false, $origin_for( $chat ), "{$method} {$route} stays closed to the chat origin" );
}

// An origin another plugin already allowed is left as it is.
$serve( 'GET', '/wc/store/v1/cart' );
ts_same( 'https://www.tehranspeaker.com', apply_filters( 'allowed_http_origin', 'https://www.tehranspeaker.com', 'https://www.tehranspeaker.com' ), 'existing allowance untouched' );

// The configured origin follows the theme / filter; a bad value closes everything.
$GLOBALS['ts_t']['filters']['ts_chat_api_chat_origin'][] = static function () { return 'https://Chat.Example/'; };
$serve( 'GET', '/wc/store/v1/products/1' );
ts_same( 'https://chat.example', TS_Chat_API_Store_CORS::chat_origin(), 'filtered origin normalised' );
ts_same( 'https://chat.example', $origin_for( 'https://chat.example' ), 'filtered origin allowed' );
ts_same( false, $origin_for( $chat ), 'the default no longer is' );
$GLOBALS['ts_t']['filters']['ts_chat_api_chat_origin'] = [ static function () { return 'https://chat.example/path'; } ];
ts_same( '', TS_Chat_API_Store_CORS::chat_origin(), 'an origin with a path is refused' );
ts_same( false, $origin_for( 'https://chat.example' ), 'misconfigured → closed' );
unset( $GLOBALS['ts_t']['filters']['ts_chat_api_chat_origin'] );
TS_Chat_API_Store_CORS::forget();
