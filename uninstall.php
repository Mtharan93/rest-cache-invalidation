<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted (not just deactivated). Removes all
 * plugin data: transients, cached version counters, and option flags.
 *
 * @package RestCacheInvalidation
 */

// Security: only run via WordPress uninstall machinery.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Remove every transient key the plugin may have written.
 * We use a known prefix so we can target them with a LIKE query through
 * the official WordPress API (get_transient / delete_transient work on
 * individual keys; for a prefix wipe we query the options table safely
 * through $wpdb with a prepared statement — no raw user input involved).
 */
global $wpdb;

// 1. Remove all cached response transients (prefix: rcin_c_).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup by transient key prefix during uninstall; no core API exists.
$rcin_transient_keys = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_rcin_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_rcin_' ) . '%'
	)
);

foreach ( $rcin_transient_keys as $rcin_option_name ) {
	// Strip the _transient_ / _transient_timeout_ prefix to get the key.
	$rcin_key = (string) preg_replace( '/^_transient_(timeout_)?/', '', $rcin_option_name );
	delete_transient( $rcin_key );
}

// 2. Remove version counter options (prefix: rcin_v_).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup by option key prefix during uninstall; no core API exists.
$rcin_option_keys = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'rcin_v_' ) . '%'
	)
);

foreach ( $rcin_option_keys as $rcin_option_name ) {
	delete_option( $rcin_option_name );
}

// 3. Remove plugin stats option.
delete_option( 'rcin_stats' );
