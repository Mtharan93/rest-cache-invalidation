<?php
/**
 * Cache_Store interface.
 *
 * All cache back-ends must implement this contract. The plugin chooses an
 * implementation at boot time and passes it everywhere via constructor
 * injection so the rest of the code never knows which back-end is active.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Cache_Store
 */
interface Cache_Store {

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false Cached value or false on miss.
	 */
	public function get( string $key ): mixed;

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store. Must be serialisable.
	 * @param int    $ttl   Time to live in seconds. 0 means no expiry.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool;

	/**
	 * Delete a single entry.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool;

	/**
	 * Remove all entries written by this plugin.
	 *
	 * @return bool True on success.
	 */
	public function flush(): bool;

	/**
	 * Return aggregate statistics.
	 *
	 * @return array{hits: int, misses: int, group_versions: array<string, int>}
	 */
	public function stats(): array;

	/**
	 * Increment the hit counter.
	 *
	 * @return void
	 */
	public function increment_hits(): void;

	/**
	 * Increment the miss counter.
	 *
	 * @return void
	 */
	public function increment_misses(): void;
}
