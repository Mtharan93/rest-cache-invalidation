<?php
/**
 * Transient_Store — Cache_Store implementation using WordPress transients.
 *
 * Used when no persistent object-cache backend is active. Transients are
 * stored in the wp_options table, which means every cache read/write hits
 * the database. This is acceptable for small traffic volumes or development
 * environments but should be replaced by a persistent object cache in
 * production.
 *
 * Key prefix: rcin_c_   (cached responses)
 * Version prefix: rcin_v_ stored as options (not transients) so they survive
 * transient flush operations.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Transient_Store.
 */
class Transient_Store implements Cache_Store {

	/**
	 * Prefix for all cached-response transient keys.
	 */
	private const KEY_PREFIX = 'rcin_c_';

	/**
	 * Option name for persisting hit/miss counters across requests.
	 */
	private const STATS_OPTION = 'rcin_stats';

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or false on miss.
	 */
	public function get( string $key ): mixed {
		return get_transient( self::KEY_PREFIX . $key );
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store. Must be serialisable.
	 * @param int    $ttl   Time to live in seconds. 0 means no expiry.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		return set_transient( self::KEY_PREFIX . $key, $value, $ttl );
	}

	/**
	 * Delete a single entry.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool {
		return delete_transient( self::KEY_PREFIX . $key );
	}

	/**
	 * Remove all plugin transients.
	 *
	 * WordPress has no native "flush by prefix" for transients, so we query
	 * the options table with a prepared LIKE statement — the only safe way to
	 * do this without introducing raw SQL or depending on private APIs.
	 *
	 * @return bool True on success.
	 */
	public function flush(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no core API to flush transients by key prefix.
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::KEY_PREFIX ) . '%'
			)
		);

		foreach ( $keys as $option_name ) {
			$transient_key = substr( $option_name, strlen( '_transient_' ) );
			delete_transient( $transient_key );
		}

		return true;
	}

	/**
	 * Return aggregate statistics.
	 *
	 * @return array{hits: int, misses: int, group_versions: array<string, int>} Cache statistics.
	 */
	public function stats(): array {
		$raw = get_option( self::STATS_OPTION, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'hits'           => (int) ( $raw['hits'] ?? 0 ),
			'misses'         => (int) ( $raw['misses'] ?? 0 ),
			'group_versions' => $this->get_group_versions(),
		);
	}

	/**
	 * Increment the hit counter.
	 *
	 * @return void
	 */
	public function increment_hits(): void {
		$this->increment_stat( 'hits' );
	}

	/**
	 * Increment the miss counter.
	 *
	 * @return void
	 */
	public function increment_misses(): void {
		$this->increment_stat( 'misses' );
	}

	/**
	 * Read version counters for all groups from options.
	 *
	 * @return array<string, int> Map of group name to version number.
	 */
	public function get_group_versions(): array {
		$versions = array();
		foreach ( Invalidator::GROUPS as $group ) {
			$v                  = (int) get_option( 'rcin_v_' . $group, 1 );
			$versions[ $group ] = $v > 0 ? $v : 1;
		}
		return $versions;
	}

	/**
	 * Get the current version for a single group.
	 *
	 * @param string $group Group name.
	 * @return int Current group version.
	 */
	public function get_group_version( string $group ): int {
		$v = (int) get_option( 'rcin_v_' . $group, 1 );
		return $v > 0 ? $v : 1;
	}

	/**
	 * Bump the version counter for a group.
	 *
	 * Stored in options (not transients) so a transient flush does not
	 * accidentally reset the version and collide with pre-existing stale data.
	 *
	 * @param string $group Group name.
	 * @return int New version number.
	 */
	public function bump_group_version( string $group ): int {
		$current = $this->get_group_version( $group );
		$next    = $current + 1;
		update_option( 'rcin_v_' . $group, $next, false );
		return $next;
	}

	/**
	 * Increment a named stat counter in the options table.
	 *
	 * @param string $key 'hits' or 'misses'.
	 * @return void
	 */
	private function increment_stat( string $key ): void {
		$raw = get_option( self::STATS_OPTION, array() );

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$raw[ $key ] = ( (int) ( $raw[ $key ] ?? 0 ) ) + 1;
		update_option( self::STATS_OPTION, $raw, false );
	}
}
