<?php
/**
 * محیط آزمایشی بدون وردپرس: توابع لازم شبیه‌سازی می‌شوند و کد واقعی افزونه اجرا می‌شود.
 *
 * @package TS_Chat_API
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'TS_CHAT_API_SECRET', str_repeat( 'k', 24 ) . str_repeat( 'Q', 24 ) . 'z9' );

	$GLOBALS['ts_t'] = [
		'assertions' => 0,
		'failures'   => [],
		'actions'    => [],
		'filters'    => [],
		'routes'     => [],
		'transients' => [],
		'options'    => [],
		'meta'       => [],
		'posts'      => [],
		'products'   => [],
		'coupons'    => [],
		'contacts'   => [],
		'sms'        => [],
		'archive'    => [],
		'images'     => [],
		'next_id'    => 5000,
		'now'        => time(),
		'notif'      => true,
		'remove'     => false,
	];

	function ts_assert( $condition, string $label ): void {
		++$GLOBALS['ts_t']['assertions'];
		if ( ! $condition ) {
			$GLOBALS['ts_t']['failures'][] = $label;
			echo "  ✗ {$label}\n";
		}
	}
	function ts_same( $expected, $actual, string $label ): void {
		ts_assert( $expected === $actual, $label . ( $expected === $actual ? '' : ' — expected ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' got ' . json_encode( $actual, JSON_UNESCAPED_UNICODE ) ) );
	}

	function get_file_data( $file, $headers ) {
		preg_match( '/Version:\s*(\S+)/', file_get_contents( $file ), $m );
		return [ 'Version' => $m[1] ];
	}
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['ts_t']['actions'][ $hook ][] = [ $callback, $priority ]; }
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['ts_t']['filters'][ $hook ][] = $callback; }
	function remove_action() {}
	function do_action( $hook, ...$args ) {
		$list = $GLOBALS['ts_t']['actions'][ $hook ] ?? [];
		usort( $list, static function ( $a, $b ) { return $a[1] <=> $b[1]; } );
		foreach ( $list as [ $callback ] ) {
			call_user_func_array( $callback, $args );
		}
	}
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ts_t']['filters'][ $hook ] ?? [] as $callback ) {
			$value = call_user_func_array( $callback, array_merge( [ $value ], $args ) );
		}
		return $value;
	}
	function is_admin() { return false; }
	function register_post_type() {}
	function __return_true() { return true; }
	function register_rest_route( $ns, $route, $args ) { $GLOBALS['ts_t']['routes'][ '/' . $ns . $route ] = $args; }

	class WP_Error {
		private $code; private $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_REST_Request {
		public $headers = []; public $params = []; public $json = null; public $route = ''; public $method = 'GET';
		public function get_method() { return $this->method; }
		public function get_header( $name ) { return $this->headers[ strtolower( str_replace( '-', '_', $name ) ) ] ?? null; }
		public function get_json_params() { return $this->json; }
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
		public function get_route() { return $this->route; }
	}
	class WP_REST_Response {
		public $data; public $status; public $headers = [];
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
		public function header( $key, $value ) { $this->headers[ $key ] = $value; }
	}

	function get_transient( $key ) {
		$entry = $GLOBALS['ts_t']['transients'][ $key ] ?? null;
		return $entry && $entry[1] > $GLOBALS['ts_t']['now'] ? $entry[0] : false;
	}
	function set_transient( $key, $value, $ttl ) { $GLOBALS['ts_t']['transients'][ $key ] = [ $value, $GLOBALS['ts_t']['now'] + $ttl ]; return true; }
	function get_option( $key, $default = false ) { return $GLOBALS['ts_t']['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['ts_t']['options'][ $key ] = $value; return true; }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['ts_t']['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['ts_t']['meta'][ $id ][ $key ] = $value; return true; }
	function get_post_type( $id ) { return isset( $GLOBALS['ts_t']['products'][ $id ] ) ? 'product' : ( $GLOBALS['ts_t']['posts'][ $id ]['post_type'] ?? false ); }
	function get_post_status( $id ) { return $GLOBALS['ts_t']['posts'][ $id ]['post_status'] ?? false; }

	function wp_strip_all_tags( $text ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return trim( strip_tags( $text ) );
	}
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function sanitize_title( $text ) { return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9-]+/', '-', (string) $text ), '-' ) ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
	function get_woocommerce_currency_symbol() { return 'تومان'; }

	/** نوشته‌ها: فقط آنچه get_posts و wp_insert_post افزونه لازم دارند. */
	function wp_insert_post( $data, $wp_error = false ) {
		$id = ++$GLOBALS['ts_t']['next_id'];
		$GLOBALS['ts_t']['posts'][ $id ] = array_merge( [ 'ID' => $id, 'menu_order' => 0 ], $data );
		foreach ( $data['meta_input'] ?? [] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}
	function get_posts( $args ) {
		$found = [];
		foreach ( $GLOBALS['ts_t']['posts'] as $id => $post ) {
			if ( ( $post['post_type'] ?? '' ) !== $args['post_type'] ) { continue; }
			$statuses = (array) ( $args['post_status'] ?? 'publish' );
			if ( ! in_array( $post['post_status'], $statuses, true ) ) { continue; }
			if ( isset( $args['post_parent'] ) && (int) ( $post['post_parent'] ?? 0 ) !== (int) $args['post_parent'] ) { continue; }
			if ( isset( $args['meta_key'] ) && get_post_meta( $id, $args['meta_key'], true ) !== $args['meta_value'] ) { continue; }
			$found[] = ( $args['fields'] ?? '' ) === 'ids' ? $id : (object) $post;
		}
		return array_slice( $found, 0, $args['posts_per_page'] ?? 10 );
	}

	class TsProduct {
		public $p;
		public function __construct( array $p ) { $this->p = array_merge( [ 'parent' => 0, 'status' => 'publish', 'in_stock' => false, 'purchasable' => true, 'type' => 'simple', 'url' => '' ], $p ); }
		public function get_id() { return $this->p['id']; }
		public function get_parent_id() { return $this->p['parent']; }
		public function get_status() { return $this->p['status']; }
		public function is_in_stock() { return $this->p['in_stock']; }
		public function is_purchasable() { return $this->p['purchasable']; }
		public function is_type( $type ) { return $this->p['type'] === $type; }
		public function get_permalink() { return $this->p['url']; }
		public function get_meta( $key, $single = true ) { return get_post_meta( $this->p['id'], $key, true ); }
		public function get_name() { return $this->p['name'] ?? ''; }
		public function get_image_id() { return $this->p['image'] ?? 0; }
		public function get_price() { return $this->p['price'] ?? ''; }
		public function get_variation_price( $which = 'min' ) { return $this->p['min_price'] ?? ''; }
	}
	function wp_get_attachment_image_url( $id, $size ) { return $GLOBALS['ts_t']['images'][ $id ][ $size ] ?? false; }
	function ts_product( array $p ) { $GLOBALS['ts_t']['products'][ $p['id'] ] = new TsProduct( $p ); }
	function wc_get_product( $id ) { return $GLOBALS['ts_t']['products'][ (int) $id ] ?? false; }

	class WC_DateTime_Stub {
		private $ts;
		public function __construct( $ts ) { $this->ts = $ts; }
		public function getTimestamp() { return $this->ts; }
	}
	class WC_Coupon {
		private $c;
		public function __construct( $id ) { $this->c = $GLOBALS['ts_t']['coupons'][ $id ]; }
		public function get_id() { return $this->c['id']; }
		public function get_meta( $key, $single = true ) { return $this->c['meta'][ $key ] ?? ''; }
		public function get_email_restrictions() { return $this->c['emails'] ?? []; }
		public function get_usage_limit() { return $this->c['limit'] ?? 0; }
		public function get_usage_count() { return $this->c['used'] ?? 0; }
		public function get_date_expires() { return isset( $this->c['expires'] ) ? new WC_DateTime_Stub( $this->c['expires'] ) : null; }
		public function get_date_created() { return new WC_DateTime_Stub( $this->c['created'] ?? 0 ); }
		public function get_description() { return $this->c['description'] ?? ''; }
		public function get_amount() { return $this->c['amount'] ?? 0; }
		public function get_discount_type() { return $this->c['type'] ?? 'percent'; }
		public function get_product_ids() { return $this->c['products'] ?? []; }
		public function get_product_categories() { return $this->c['categories'] ?? []; }
		public function get_code() { return $this->c['code']; }
	}
	function ts_coupon( array $c ) {
		$GLOBALS['ts_t']['coupons'][ $c['id'] ] = $c;
		$GLOBALS['ts_t']['posts'][ $c['id'] ] = [ 'ID' => $c['id'], 'post_type' => 'shop_coupon', 'post_status' => $c['status'] ?? 'publish' ];
		update_post_meta( $c['id'], '_ts_public_campaign', $c['meta']['_ts_public_campaign'] ?? '' );
	}

	/** PWSMS: همان رفتار عمومی که افزونه‌ی ما به آن تکیه می‌کند. */
	class TsPwsms {
		public function has_notif_condition( $key, $id ) { return $GLOBALS['ts_t']['notif']; }
		public function replace_tags( $key, $id, $parent ) {
			return 'موجود شد: ' . rawurldecode( rawurldecode( wc_get_product( $id )->get_permalink() ) ) . ' لغو ۱۱';
		}
		public function send_sms( $data ) {
			$GLOBALS['ts_t']['sms'][] = $data;
			$GLOBALS['ts_t']['archive'][] = [ $data['type'], $data['post_id'], implode( ',', $data['mobile'] ) ];
			return true;
		}
		public function get_option( $key ) { return $key === 'notif_no_stock_remove_contacts' ? $GLOBALS['ts_t']['remove'] : ''; }
	}
	function PWSMS() { static $i; return $i ?: $i = new TsPwsms(); }
}

namespace PW\PWSMS\Enums {
	class EventsEnum { public const NEWSLETTER_IN_STOCK_AUTOMATIC = 11; }
}

namespace PW\PWSMS\Subscription {
	class Contacts {
		public static function get_contact_by_mobile( $product_id, $mobile ) {
			$tail = substr( ltrim( $mobile, '0' ), -10 );
			foreach ( $GLOBALS['ts_t']['contacts'] as $row ) {
				if ( (int) $row['product_id'] === (int) $product_id && strpos( $row['mobile'], $tail ) !== false ) {
					return $row;
				}
			}
			return null;
		}
		public static function insert_contact( $data ) {
			$id = count( $GLOBALS['ts_t']['contacts'] ) + 1;
			$GLOBALS['ts_t']['contacts'][ $id ] = [ 'id' => $id, 'product_id' => (int) $data['product_id'], 'mobile' => $data['mobile'], 'groups' => implode( ',', (array) $data['groups'] ) ];
			return 1;
		}
		public static function update_contact( array $data ) {
			$GLOBALS['ts_t']['contacts'][ $data['id'] ]['groups'] = implode( ',', array_unique( (array) $data['groups'] ) );
			return 1;
		}
		public static function get_contacts_mobile( int $product_id, string $group ) {
			$out = [];
			foreach ( $GLOBALS['ts_t']['contacts'] as $row ) {
				if ( (int) $row['product_id'] === $product_id && strpos( $row['groups'], $group ) !== false ) {
					$out[] = $row['mobile'];
				}
			}
			return $out;
		}
	}
}

namespace PW\PWSMS {
	class Helper {
		public static function remove_sent_mobiles( $mobiles, $type, $post_id ) {
			$sent = [];
			foreach ( $GLOBALS['ts_t']['archive'] as [ $t, $p, $r ] ) {
				if ( $t === $type && (int) $p === (int) $post_id ) {
					$sent = array_merge( $sent, explode( ',', $r ) );
				}
			}
			return array_values( array_diff( $mobiles, $sent ) );
		}
		public static function remove_contacts_group( array $mobiles, $group, $post_id ) { $GLOBALS['ts_t']['removed'][] = [ $mobiles, $group, $post_id ]; }
	}
}

namespace {
	require dirname( __DIR__ ) . '/ts-chat-api.php';
	ts_chat_api_boot();
	do_action( 'rest_api_init' );

	/**
	 * یک درخواست REST کامل: مجوز، فراخوانی و فیلتر خطای قرارداد، مثل وردپرس.
	 */
	function ts_request( string $method, string $route, array $json = null, array $params = [], ?string $token = TS_CHAT_API_SECRET, array $headers = [] ): WP_REST_Response {
		$args               = $GLOBALS['ts_t']['routes'][ $route ];
		$request            = new WP_REST_Request();
		$request->route     = $route;
		$request->json      = $json;
		$request->params    = $params;
		$request->headers   = $headers;
		if ( $token !== null ) {
			$request->headers['authorization'] = 'Bearer ' . $token;
		}
		ts_assert( $args['methods'] === $method, "{$route} answers {$method}" );
		$allowed  = call_user_func( $args['permission_callback'], $request );
		$response = $allowed === true ? call_user_func( $args['callback'], $request ) : $allowed;
		return apply_filters( 'rest_request_after_callbacks', $response, $args, $request );
	}

	function ts_reset_limits(): void { $GLOBALS['ts_t']['transients'] = []; }
}
