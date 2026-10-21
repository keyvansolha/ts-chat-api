<?php
/**
 * محدودیت نرخ درخواست‌های ts-chat/v1.
 *
 * @package TS_Chat_API
 */

defined( 'ABSPATH' ) || exit;

/**
 * پنجره‌ی ثابت روی transient: هر کلید یک شمارنده و زمان پایان پنجره دارد.
 *
 * شمارش اتمیک نیست؛ برای سقف‌های این مسیر (۵ در ساعت برای هر موبایل، ۶۰ در
 * دقیقه در کل) چند درخواست هم‌زمان اضافه ضرری ندارد.
 */
class TS_Chat_API_Rate_Limit {

	/** پیشوند transientها، که uninstall.php هم آن را پاک می‌کند. */
	const PREFIX = 'ts_chat_api_rl_';

	/**
	 * یک درخواست در این پنجره ثبت می‌کند؛ اگر سقف پر شده باشد false برمی‌گرداند.
	 *
	 * @param string $bucket نام سطل (کلید خام هش می‌شود تا موبایلی ذخیره نشود).
	 * @param int    $limit  سقف درخواست در پنجره.
	 * @param int    $window طول پنجره به ثانیه.
	 */
	public static function hit( string $bucket, int $limit, int $window ): bool {
		$key   = self::PREFIX . substr( hash( 'sha256', $bucket ), 0, 32 );
		$now   = time();
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['until'] ) || (int) $state['until'] <= $now ) {
			$state = [ 'count' => 0, 'until' => $now + $window ];
		}
		if ( (int) $state['count'] >= $limit ) {
			return false;
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, max( 1, (int) $state['until'] - $now ) );

		return true;
	}
}
