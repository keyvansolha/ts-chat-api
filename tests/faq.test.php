<?php
/**
 * WP-116: POST ts-chat/v1/product-faq و نمایش فقط موارد تأییدشده.
 *
 * @package TS_Chat_API
 */

ts_reset_limits();
ts_product( [ 'id' => 271509 ] );
ts_product( [ 'id' => 300 ] );
ts_product( [ 'id' => 301, 'status' => 'draft' ] );
update_post_meta( 300, 'faqs', [ [ 'question' => 'گارانتی دارد؟', 'answer' => 'بله' ] ] );

$body = [
	'product_id' => 271509,
	'question'   => '<b>با آیفون</b> هم کار می‌کند؟',
	'answer'     => '<script>alert(1)</script>بله، با <a href="x" onclick="y">بلوتوث ۵.۳</a> به آیفون وصل می‌شود.',
	'source_ref' => 'chat:c_8f2a',
];
$created = ts_request( 'POST', '/ts-chat/v1/product-faq', $body );
ts_same( 201, $created->status, 'new suggestion → 201' );
ts_same( 'pending', $created->data['status'], 'status pending' );
$id   = $created->data['id'];
$post = $GLOBALS['ts_t']['posts'][ $id ];
ts_same( [ 'ts_product_faq', 'pending', 271509 ], [ $post['post_type'], $post['post_status'], $post['post_parent'] ], 'a pending ts_product_faq under the product' );
ts_same( 'با آیفون هم کار می‌کند؟', $post['post_title'], 'HTML stripped from the question' );
ts_same( 'بله، با بلوتوث ۵.۳ به آیفون وصل می‌شود.', $post['post_content'], 'HTML and script stripped from the answer' );
ts_same( 'chat:c_8f2a', get_post_meta( $id, '_ts_chat_source_ref', true ), 'source reference kept for the reviewer' );

$dupe = ts_request( 'POST', '/ts-chat/v1/product-faq', array_merge( $body, [ 'question' => 'با آيفون هم كار مي‌كند ؟' ] ) );
ts_same( [ 200, [ 'status' => 'exists', 'id' => $id ] ], [ $dupe->status, $dupe->data ], 'same product + normalised question → 200 exists' );
ts_same( TS_Chat_API_FAQ::question_key( 'آزمون؛ لطفاً تأیید نشود' ), TS_Chat_API_FAQ::question_key( 'آزمون لطفا تایید نشود' ), 'hamza and tanween variants are the same question' );
$own = ts_request( 'POST', '/ts-chat/v1/product-faq', [ 'product_id' => 300, 'question' => 'گارانتی دارد', 'answer' => 'بله' ] );
ts_same( [ 200, 'exists' ], [ $own->status, $own->data['status'] ], 'a question already in the product FAQ field is a duplicate too' );
$other = ts_request( 'POST', '/ts-chat/v1/product-faq', array_merge( $body, [ 'product_id' => 300 ] ) );
ts_same( 201, $other->status, 'the same question on another product is new' );

$cases = [
	'unknown product'  => [ array_merge( $body, [ 'product_id' => 999 ] ), 404, 'not_found' ],
	'draft product'    => [ array_merge( $body, [ 'product_id' => 301 ] ), 404, 'not_found' ],
	'question too long' => [ array_merge( $body, [ 'question' => str_repeat( 'س', 301 ) ] ), 422, 'invalid' ],
	'answer too long'  => [ array_merge( $body, [ 'answer' => str_repeat( 'ج', 2001 ) ] ), 422, 'invalid' ],
	'empty answer'     => [ array_merge( $body, [ 'answer' => '<p> </p>' ] ), 422, 'invalid' ],
	'non-string'       => [ array_merge( $body, [ 'question' => [ 'x' ] ] ), 422, 'invalid' ],
];
foreach ( $cases as $label => [ $payload, $code, $error ] ) {
	$response = ts_request( 'POST', '/ts-chat/v1/product-faq', $payload );
	ts_same( [ $code, [ 'code' => $error ] ], [ $response->status, $response->data ], "{$label} → {$code} {$error}" );
}
ts_same( 201, ts_request( 'POST', '/ts-chat/v1/product-faq', array_merge( $body, [ 'question' => str_repeat( 'س', 300 ), 'answer' => str_repeat( 'ج', 2000 ) ] ) )->status, '300/2000 characters exactly is accepted' );
ts_same( [ 401, [ 'code' => 'unauthorized' ] ], [ ts_request( 'POST', '/ts-chat/v1/product-faq', $body, [], 'nope' )->status, ts_request( 'POST', '/ts-chat/v1/product-faq', $body, [], 'nope' )->data ], 'bad secret → 401' );

// فقط موارد منتشرشده روی صفحه‌ی محصول.
$rows = apply_filters( 'ts_post_faqs', [], 271509 );
ts_same( [], $rows, 'nothing renders before a human approves' );
$GLOBALS['ts_t']['posts'][ $id ]['post_status'] = 'publish';
$rows = apply_filters( 'ts_post_faqs', [ [ 'question' => 'سؤال خود محصول', 'answer' => 'پاسخ' ] ], 271509 );
ts_same( 2, count( $rows ), 'approved item appended after the product\'s own rows' );
ts_same( [ 'question' => 'با آیفون هم کار می‌کند؟', 'answer' => 'بله، با بلوتوث ۵.۳ به آیفون وصل می‌شود.' ], $rows[1], 'approved row in the theme FAQ shape' );
$GLOBALS['ts_t']['posts'][ $id ]['post_title'] = '<img src=x onerror=alert(1)>';
$GLOBALS['ts_t']['posts'][ $id ]['post_content'] = 'a < b & "c"';
$rows = apply_filters( 'ts_post_faqs', [], 271509 );
ts_same( '', $rows[0]['question'] ?? '', 'an admin edit with markup still renders as plain text' );
ts_same( 'a &lt; b &amp; &quot;c&quot;', $rows[0]['answer'], 'answer is escaped for the theme renderer' );
ts_same( [], apply_filters( 'ts_post_faqs', [], 5001 ), 'non-product posts are untouched' );
