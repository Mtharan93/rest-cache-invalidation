<?php
/**
 * PHPUnit bootstrap for REST Cache Invalidation tests.
 *
 * Loads the WordPress test suite (provided by wp-env / the wordpress-develop
 * test scaffold) and then loads the plugin so its classes are available.
 *
 * Environment variable WP_TESTS_DIR must point to the WordPress test library.
 * @wordpress/env sets this automatically when running `wp-env run tests-cli`.
 *
 * @package RestCacheInvalidation\Tests
 */

// phpcs:disable WordPress.NamingConventions

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Fallback for local wp-env usage.
	$_tests_dir = '/tmp/wordpress-tests-lib';
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
