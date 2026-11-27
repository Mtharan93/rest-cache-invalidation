<?php
/**
 * PHPUnit bootstrap for REST Cache Invalidation tests.
 *
 * Supports both:
 * - Unit tests (`--testsuite unit`): runs on the host using Composer/Brain\Monkey
 *   without requiring WordPress core or a database.
 * - Integration tests (`--testsuite integration`): loads the WordPress test suite
 *   (provided by wp-env / wordpress-develop) and then loads the plugin.
 *
 * @package RestCacheInvalidation\Tests
 */

// phpcs:disable WordPress.NamingConventions

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$_argv         = $_SERVER['argv'] ?? array();
$_is_unit_only = false;

foreach ( $_argv as $_idx => $_arg ) {
	if ( '--testsuite=unit' === $_arg ) {
		$_is_unit_only = true;
		break;
	}
	if ( '--testsuite' === $_arg && isset( $_argv[ $_idx + 1 ] ) && 'unit' === $_argv[ $_idx + 1 ] ) {
		$_is_unit_only = true;
		break;
	}
	if ( str_contains( str_replace( '\\', '/', (string) $_arg ), 'tests/unit' ) ) {
		$_is_unit_only = true;
		break;
	}
}

if ( $_is_unit_only ) {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/**
		 * Minimal WP_REST_Request stub so PHPUnit can mock its methods without WordPress.
		 */
		class WP_REST_Request {
			/**
			 * Get the matched route.
			 *
			 * @return string
			 */
			public function get_route(): string {
				return '';
			}

			/**
			 * Get query parameters.
			 *
			 * @return array<string, mixed>
			 */
			public function get_query_params(): array {
				return array();
			}
		}
	}

	return;
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	if ( file_exists( '/wordpress-phpunit/includes/functions.php' ) ) {
		$_tests_dir = '/wordpress-phpunit';
	} else {
		$_tests_dir = '/tmp/wordpress-tests-lib';
	}
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "ERROR: Could not find the WordPress test library at '$_tests_dir'.\n";
	echo "Set the WP_TESTS_DIR environment variable or use wp-env to run tests.\n";
	exit( 1 );
}

// Give access to tests_add_filter() early.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/rest-cache-invalidation.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Bootstrap the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
