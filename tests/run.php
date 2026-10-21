<?php
/**
 * اجراکننده‌ی تست‌ها: php tests/run.php
 *
 * @package TS_Chat_API
 */

require __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/*.test.php' );
sort( $files );
foreach ( $files as $file ) {
	echo '▶ ' . basename( $file ) . "\n";
	try {
		require $file;
	} catch ( Throwable $error ) {
		$GLOBALS['ts_t']['failures'][] = basename( $file ) . ': ' . $error->getMessage() . ' @' . $error->getLine();
		echo '  ✗ ' . $error->getMessage() . ' (' . $error->getFile() . ':' . $error->getLine() . ")\n";
	}
}

printf( "%d files, %d assertions, %d failures\n", count( $files ), $GLOBALS['ts_t']['assertions'], count( $GLOBALS['ts_t']['failures'] ) );
exit( $GLOBALS['ts_t']['failures'] ? 1 : 0 );
