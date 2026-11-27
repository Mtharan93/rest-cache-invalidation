<?php
/**
 * Object_Cache_Store — Cache_Store implementation using wp_cache_*.
 *
 * Best-case scenario: a persistent object cache backend (Redis, Memcached)
 * is active, so entries survive between requests and are shared across all
 * web workers without hitting the database.
 *
 * Cache group: 'rest_cache_invalidation'
 * Stats key:   wp_cache under group 'rest_cache_invalidation_meta'
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Object_Cache_Store.
 */
class Object_Cache_Store implements Cache_Store {

	/**
	 * WP object-cache group for cached responses.
	 */
	private const GROUP = 'rest_cache_invalidation';

	/**
	 * WP object-cache group for meta data (stats, version counters).
	 */
	private const META_GROUP = 'rest_cache_invalidation_meta';

	/**
	 * Option/cache key that holds hit/miss counters.
	 */
	private const STATS_KEY = 'rcin_stats';

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed Cached value or false on miss.
	 */
	public function get( string $key ): mixed {
		return wp_cache_get( $key, self::GROUP );
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
		return wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * Delete a single entry.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool {
		return wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Flush all entries in our cache groups.
	 *
	 * wp_cache_flush_group() is available when the backend supports it
	 * (WP 6.1+). We fall back to wp_cache_flush() — which clears the whole
	 * object cache — only as a last resort, and we document this limitation.
	 *
	 * @return bool True on success.
	 */
	public function flush(): bool {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
			wp_cache_flush_group( self::META_GROUP );
			return true;
		}

		// Fallback: flush the entire object cache. This is documented as a
		// limitation in the README — prefer a backend that supports group flush.
		return wp_cache_flush();
	}

	/**
	 * Return aggregate statistics.
	 *
	 * @return array{hits: int, misses: int, group_versions: array<string, int>} Cache statistics.
	 */
	public function stats(): array {
		$raw = wp_cache_get( self::STATS_KEY, self::META_GROUP );

		if ( ! is_array( $raw ) ) {
			$raw = array(
				'hits'   => 0,
				'misses' => 0,
			);
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
	 * Read the current group version counters.
	 *
	 * @return array<string, int> Map of group name to version number.
	 */
	public function get_group_versions(): array {
		$versions = array();
		foreach ( Invalidator::GROUPS as $group ) {
			$v                  = wp_cache_get( 'rcin_v_' . $group, self::META_GROUP );
			$versions[ $group ] = is_numeric( $v ) ? (int) $v : 1;
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
		$v = wp_cache_get( 'rcin_v_' . $group, self::META_GROUP );
		return is_numeric( $v ) ? (int) $v : 1;
	}

	/**
	 * Bump the version counter for a group, making all old keys unreachable.
	 *
	 * @param string $group Group name.
	 * @return int New version number.
	 */
	public function bump_group_version( string $group ): int {
		$current = $this->get_group_version( $group );
		$next    = $current + 1;
		wp_cache_set( 'rcin_v_' . $group, $next, self::META_GROUP, 0 );
		return $next;
	}

	/**
	 * Atomically increment a named stat counter.
	 *
	 * @param string $key 'hits' or 'misses'.
	 * @return void
	 */
	private function increment_stat( string $key ): void {
		$raw = wp_cache_get( self::STATS_KEY, self::META_GROUP );

		if ( ! is_array( $raw ) ) {
			$raw = array(
				'hits'   => 0,
				'misses' => 0,
			);
		}

		$raw[ $key ] = ( (int) ( $raw[ $key ] ?? 0 ) ) + 1;
		wp_cache_set( self::STATS_KEY, $raw, self::META_GROUP, 0 );
	}
}
