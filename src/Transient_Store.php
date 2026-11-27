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
 * Key prefix: rci_c_   (cached responses)
 * Version prefix: rci_v_ stored as options (not transients) so they survive
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
 * Class Transient_Store
 */
class Transient_Store implements Cache_Store {

	/**
	 * Prefix for all cached-response transient keys.
	 */
	private const KEY_PREFIX = 'rci_c_';

	/**
	 * Option name for persisting hit/miss counters across requests.
	 */
	private const STATS_OPTION = 'rci_stats';

	/** @inheritDoc */
	public function get( string $key ): mixed {
		$value = get_transient( self::KEY_PREFIX . $key );

		return $value;
	}

	/** @inheritDoc */
	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		return set_transient( self::KEY_PREFIX . $key, $value, $ttl );
	}

	/** @inheritDoc */
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
	 * @inheritDoc
	 */
	public function flush(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::KEY_PREFIX ) . '%'
			)
		);

		foreach ( $keys as $option_name ) {
			$transient_key = str_replace( '_transient_', '', $option_name );
			delete_transient( $transient_key );
		}

		return true;
	}

	/** @inheritDoc */
	public function stats(): array {
		$raw = get_option( self::STATS_OPTION, array() );

		return array(
			'hits'           => (int) ( $raw['hits'] ?? 0 ),
			'misses'         => (int) ( $raw['misses'] ?? 0 ),
			'group_versions' => $this->get_group_versions(),
		);
	}

	/** @inheritDoc */
	public function increment_hits(): void {
		$this->increment_stat( 'hits' );
	}

	/** @inheritDoc */
	public function increment_misses(): void {
		$this->increment_stat( 'misses' );
	}

	/**
	 * Read version counters for all groups from options.
	 *
	 * @return array<string, int>
	 */
	public function get_group_versions(): array {
		$versions = array();
		foreach ( Invalidator::GROUPS as $group ) {
			$v                = (int) get_option( 'rci_v_' . $group, 1 );
			$versions[ $group ] = $v > 0 ? $v : 1;
		}
		return $versions;
	}

	/**
	 * Get the current version for a single group.
	 *
	 * @param string $group Group name.
	 * @return int
	 */
	public function get_group_version( string $group ): int {
		$v = (int) get_option( 'rci_v_' . $group, 1 );
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
		update_option( 'rci_v_' . $group, $next, false );
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
