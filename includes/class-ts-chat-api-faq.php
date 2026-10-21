<?php
/**
 * سؤال‌های متداول پیشنهادی چت‌بات، با تأیید انسانی در پیشخوان.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /ts-chat/v1/product-faq (WP-116).
 *
 * هر پیشنهاد یک نوشته از نوع `ts_product_faq` است: والد = محصول، عنوان = سؤال،
 * محتوا = پاسخ، وضعیت «در انتظار بررسی». فقط مدیر با انتشار آن را تأیید می‌کند،
 * و فقط موارد منتشرشده از طریق فیلتر `ts_post_faqs` قالب در بخش سؤالات متداول
 * محصول و اسکیما‌ی FAQPage همان بخش دیده می‌شوند.
 */
class TS_Chat_API_FAQ {

	/** نوع نوشته. */
	const POST_TYPE = 'ts_product_faq';

	/** متای مرجع گفتگوی چت‌بات. */
	const SOURCE_META = '_ts_chat_source_ref';

	/** متای کلید نرمال‌شده‌ی سؤال برای تشخیص تکراری. */
	const KEY_META = '_ts_chat_question_key';

	/** سقف طول سؤال و پاسخ. */
	const MAX_QUESTION = 300;
	const MAX_ANSWER   = 2000;
	const MAX_SOURCE   = 200;

	/** سقف کل درخواست‌ها در دقیقه. */
	const GLOBAL_LIMIT = 60;

	/**
	 * ثبت نوع نوشته، مسیر، پیشخوان و نمایش.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'ts_post_faqs', [ $this, 'append_approved' ], 10, 2 );

		if ( is_admin() ) {
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'columns' ] );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'column' ], 10, 2 );
			add_action( 'restrict_manage_posts', [ $this, 'product_filter' ] );
			add_action( 'pre_get_posts', [ $this, 'apply_product_filter' ] );
			add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );
			add_action( 'admin_post_ts_chat_faq_approve', [ $this, 'approve' ] );
			add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
			add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_product' ], 10, 2 );
		}
	}

	/**
	 * نوع نوشته، فقط در پیشخوان و زیر منوی محصولات.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'               => 'سؤالات پیشنهادی چت‌بات',
					'singular_name'      => 'سؤال پیشنهادی',
					'menu_name'          => 'سؤالات پیشنهادی چت‌بات',
					'edit_item'          => 'ویرایش سؤال پیشنهادی',
					'search_items'       => 'جستجوی سؤال‌ها',
					'not_found'          => 'سؤالی پیدا نشد.',
					'not_found_in_trash' => 'سؤالی در زباله‌دان نیست.',
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=product',
				'show_in_rest'    => false,
				'supports'        => [ 'title', 'editor' ],
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'rewrite'         => false,
				'query_var'       => false,
			]
		);
	}

	/**
	 * مسیر REST.
	 */
	public function register_routes(): void {
		register_rest_route(
			TS_CHAT_API_NAMESPACE,
			'/product-faq',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ 'TS_Chat_API_Auth', 'permission' ],
			]
		);
	}

	/**
	 * متن ساده: بدون HTML و نویسه‌ی کنترلی، فاصله‌ها یکی.
	 *
	 * @param mixed $value ورودی.
	 */
	public static function plain( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\n{3,}/u', "\n\n", $text ) );
	}

	/**
	 * کلید مقایسه‌ی سؤال: ی/ک عربی یکسان، نیم‌فاصله و علائم حذف، حروف کوچک.
	 *
	 * @param string $question سؤال.
	 */
	public static function question_key( string $question ): string {
		$question = strtr(
			$question,
			[
				'ي' => 'ی',
				'ى' => 'ی',
				'ك' => 'ک',
				'ۀ' => 'ه',
				'ة' => 'ه',
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ؤ' => 'و',
				'ئ' => 'ی',
				"\u{200c}" => '',
			]
		);
		// اعراب، تنوین و تشدید («لطفاً» = «لطفا») در مقایسه نقشی ندارند.
		$question = (string) preg_replace( '/\p{Mn}+/u', '', $question );
		$question = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question, 'UTF-8' ) : strtolower( $question );

		return (string) preg_replace( '/[\p{P}\p{S}\p{Z}\s]+/u', '', $question );
	}

	/**
	 * طول بر حسب نویسه.
	 *
	 * @param string $text متن.
	 */
	private static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * ثبت پیشنهاد.
	 *
	 * @param WP_REST_Request $request درخواست.
	 */
	public function create( $request ): WP_REST_Response {
		if ( ! TS_Chat_API_Rate_Limit::hit( 'product-faq:all', self::GLOBAL_LIMIT, MINUTE_IN_SECONDS ) ) {
			return TS_Chat_API_Auth::error( 'rate_limited', 429 );
		}

		$body       = $request->get_json_params();
		$body       = is_array( $body ) ? $body : [];
		$product_id = isset( $body['product_id'] ) && is_numeric( $body['product_id'] ) ? (int) $body['product_id'] : 0;
		$question   = self::plain( $body['question'] ?? null );
		$answer     = self::plain( $body['answer'] ?? null );
		$source     = self::plain( $body['source_ref'] ?? '' );

		if (
			$question === ''
			|| $answer === ''
			|| self::length( $question ) > self::MAX_QUESTION
			|| self::length( $answer ) > self::MAX_ANSWER
			|| self::length( $source ) > self::MAX_SOURCE
			|| self::question_key( $question ) === ''
		) {
			return TS_Chat_API_Auth::error( 'invalid', 422 );
		}

		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product || $product->get_parent_id() > 0 || $product->get_status() !== 'publish' ) {
			return TS_Chat_API_Auth::error( 'not_found', 404 );
		}

		$key      = self::question_key( $question );
		$existing = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_parent'      => $product_id,
				'post_status'      => [ 'pending', 'publish', 'draft', 'private' ],
				'meta_key'         => self::KEY_META,
				'meta_value'       => $key,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			]
		);
		if ( ! empty( $existing ) ) {
			return $this->response( [ 'status' => 'exists', 'id' => (int) $existing[0] ], 200 );
		}
		// سؤالی که مدیر خودش در فیلد سؤالات متداول محصول نوشته هم تکراری است.
		$own = get_post_meta( $product_id, 'faqs', true );
		foreach ( is_array( $own ) ? $own : [] as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : $row;
			if ( is_array( $row ) && self::question_key( self::plain( (string) ( $row['question'] ?? '' ) ) ) === $key ) {
				return $this->response( [ 'status' => 'exists', 'id' => 0 ], 200 );
			}
		}

		$id = wp_insert_post(
			[
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'pending',
				'post_parent'  => $product_id,
				'post_title'   => $question,
				'post_content' => $answer,
				'meta_input'   => [
					self::KEY_META    => $key,
					self::SOURCE_META => $source,
				],
			],
			true
		);
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			return TS_Chat_API_Auth::error( 'error', 500 );
		}

		return $this->response( [ 'id' => (int) $id, 'status' => 'pending' ], 201 );
	}

	/**
	 * پاسخ بدون کش.
	 *
	 * @param array $data   بدنه.
	 * @param int   $status وضعیت.
	 */
	private function response( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * سؤال‌های تأییدشده‌ی یک محصول، بعد از سؤال‌های خود محصول.
	 *
	 * @param array $rows       ردیف‌های فعلی.
	 * @param int   $product_id شناسه‌ی نوشته.
	 */
	public function append_approved( $rows, $product_id ): array {
		$rows = is_array( $rows ) ? $rows : [];
		if ( (int) $product_id <= 0 || get_post_type( (int) $product_id ) !== 'product' ) {
			return $rows;
		}

		$approved = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_parent'      => (int) $product_id,
				'post_status'      => 'publish',
				'orderby'          => [ 'menu_order' => 'ASC', 'date' => 'ASC' ],
				'posts_per_page'   => 50,
				'suppress_filters' => true,
			]
		);
		foreach ( $approved as $post ) {
			$rows[] = [
				'question' => self::plain( $post->post_title ),
				'answer'   => esc_html( self::plain( $post->post_content ) ),
			];
		}

		return $rows;
	}

	/**
	 * ستون‌های فهرست پیشخوان.
	 *
	 * @param array $columns ستون‌ها.
	 */
	public function columns( $columns ): array {
		return [
			'cb'          => $columns['cb'] ?? '',
			'title'       => 'سؤال',
			'ts_answer'   => 'پاسخ',
			'ts_product'  => 'محصول',
			'ts_source'   => 'مرجع گفتگو',
			'date'        => $columns['date'] ?? 'تاریخ',
		];
	}

	/**
	 * محتوای ستون‌ها.
	 *
	 * @param string $column  ستون.
	 * @param int    $post_id نوشته.
	 */
	public function column( $column, $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( $column === 'ts_answer' ) {
			echo esc_html( wp_trim_words( self::plain( $post->post_content ), 30 ) );
		} elseif ( $column === 'ts_product' && $post->post_parent > 0 ) {
			printf(
				'<a href="%s">%s</a> <a href="%s">(فقط همین محصول)</a>',
				esc_url( (string) get_edit_post_link( $post->post_parent ) ),
				esc_html( get_the_title( $post->post_parent ) ),
				esc_url( add_query_arg( [ 'post_type' => self::POST_TYPE, 'ts_product' => (int) $post->post_parent ], admin_url( 'edit.php' ) ) )
			);
		} elseif ( $column === 'ts_source' ) {
			echo esc_html( (string) get_post_meta( $post_id, self::SOURCE_META, true ) );
		}
	}

	/**
	 * فیلتر «شناسه‌ی محصول» بالای فهرست؛ فیلتر وضعیت را خود وردپرس دارد.
	 *
	 * @param string $post_type نوع نوشته‌ی صفحه.
	 */
	public function product_filter( $post_type ): void {
		if ( $post_type !== self::POST_TYPE ) {
			return;
		}
		$value = isset( $_GET['ts_product'] ) ? absint( wp_unslash( $_GET['ts_product'] ) ) : 0;
		printf(
			'<input type="number" min="1" name="ts_product" value="%s" placeholder="شناسه‌ی محصول" aria-label="شناسه‌ی محصول" style="width:9em">',
			$value > 0 ? esc_attr( (string) $value ) : ''
		);
	}

	/**
	 * اعمال فیلتر محصول.
	 *
	 * @param WP_Query $query پرس‌وجو.
	 */
	public function apply_product_filter( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== self::POST_TYPE ) {
			return;
		}
		$product = isset( $_GET['ts_product'] ) ? absint( wp_unslash( $_GET['ts_product'] ) ) : 0;
		if ( $product > 0 ) {
			$query->set( 'post_parent', $product );
		}
	}

	/**
	 * عمل سریع «تأیید و انتشار» برای موارد در انتظار.
	 *
	 * @param array   $actions عمل‌ها.
	 * @param WP_Post $post    نوشته.
	 */
	public function row_actions( $actions, $post ): array {
		if ( $post->post_type !== self::POST_TYPE || $post->post_status !== 'pending' || ! current_user_can( 'publish_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg( [ 'action' => 'ts_chat_faq_approve', 'post' => (int) $post->ID ], admin_url( 'admin-post.php' ) ),
			'ts_chat_faq_approve_' . $post->ID
		);

		return [ 'ts_approve' => '<a href="' . esc_url( $url ) . '">تأیید و انتشار</a>' ] + $actions;
	}

	/**
	 * انتشار یک پیشنهاد با تأیید مدیر.
	 */
	public function approve(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( 'ts_chat_faq_approve_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== self::POST_TYPE || ! current_user_can( 'publish_post', $post_id ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
		wp_safe_redirect( add_query_arg( [ 'post_type' => self::POST_TYPE, 'post_status' => 'pending' ], admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * جعبه‌ی محصول در صفحه‌ی ویرایش.
	 */
	public function meta_box(): void {
		add_meta_box( 'ts_chat_faq_product', 'محصول', [ $this, 'render_meta_box' ], self::POST_TYPE, 'side' );
	}

	/**
	 * محتوای جعبه‌ی محصول.
	 *
	 * @param WP_Post $post نوشته.
	 */
	public function render_meta_box( $post ): void {
		wp_nonce_field( 'ts_chat_faq_product', 'ts_chat_faq_product_nonce' );
		printf(
			'<p><label for="ts_chat_faq_parent">شناسه‌ی محصول</label><br><input type="number" min="1" id="ts_chat_faq_parent" name="ts_chat_faq_parent" value="%s"></p><p>%s</p><p>مرجع گفتگو: <code>%s</code></p>',
			esc_attr( (string) (int) $post->post_parent ),
			$post->post_parent > 0 ? esc_html( get_the_title( $post->post_parent ) ) : '—',
			esc_html( (string) get_post_meta( $post->ID, self::SOURCE_META, true ) )
		);
		echo '<p class="description">پاسخ متن ساده است و HTML آن هنگام نمایش حذف می‌شود. «انتشار» یعنی تأیید؛ «زباله‌دان» یعنی رد.</p>';
	}

	/**
	 * ذخیره‌ی محصول و کلید سؤال پس از ویرایش مدیر.
	 *
	 * @param int     $post_id نوشته.
	 * @param WP_Post $post    شیء نوشته.
	 */
	public function save_product( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		update_post_meta( $post_id, self::KEY_META, self::question_key( self::plain( $post->post_title ) ) );

		if (
			! isset( $_POST['ts_chat_faq_product_nonce'], $_POST['ts_chat_faq_parent'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ts_chat_faq_product_nonce'] ) ), 'ts_chat_faq_product' )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}
		$parent  = absint( wp_unslash( $_POST['ts_chat_faq_parent'] ) );
		$product = $parent > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $parent ) : null;
		if ( $product && $product->get_parent_id() === 0 && (int) $post->post_parent !== $parent ) {
			remove_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_product' ], 10 );
			wp_update_post( [ 'ID' => $post_id, 'post_parent' => $parent ] );
			add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_product' ], 10, 2 );
		}
	}
}
