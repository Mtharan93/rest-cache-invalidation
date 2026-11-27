<?php
/**
 * WP-CLI command: wp rest-cache
 *
 * Subcommands:
 *   flush   Remove all cached REST responses.
 *   stats   Show hit/miss counters and current group version numbers.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation\CLI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use RestCacheInvalidation\Cache_Store;

/**
 * Manage the REST Cache Invalidation plugin cache.
 */
class Command {

	/**
	 * Active cache store.
	 *
	 * @var Cache_Store
	 */
	private Cache_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Cache_Store $store Active cache store instance.
	 */
	public function __construct( Cache_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Flush all cached REST API responses.
	 *
	 * ## EXAMPLES
	 *
	 *   wp rest-cache flush
	 *
	 * @when after_wp_load
	 */
	public function flush(): void {
		$result = $this->store->flush();

		if ( $result ) {
			\WP_CLI::success( 'REST cache flushed.' );
		} else {
			\WP_CLI::error( 'Failed to flush REST cache.' );
		}
	}

	/**
	 * Show cache statistics.
	 *
	 * Displays hit/miss counts and the current version number for each
	 * invalidation group. A higher version number means the group has been
	 * invalidated that many times since the counters were last reset.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table (default), json, csv.
	 *
	 * ## EXAMPLES
	 *
	 *   wp rest-cache stats
	 *   wp rest-cache stats --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param list<string>         $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function stats( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Required by WP-CLI subcommand callback signature.
		$data   = $this->store->stats();
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		// Hits / misses summary.
		$summary = array(
			array(
				'Metric' => 'Hits',
				'Value'  => $data['hits'],
			),
			array(
				'Metric' => 'Misses',
				'Value'  => $data['misses'],
			),
		);

		\WP_CLI::line( 'Cache counters:' );
		\WP_CLI\Utils\format_items( $format, $summary, array( 'Metric', 'Value' ) );

		// Group versions.
		$versions = array();
		foreach ( $data['group_versions'] as $group => $version ) {
			$versions[] = array(
				'Group'   => $group,
				'Version' => $version,
			);
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( 'Group versions (bump = invalidation):' );
		\WP_CLI\Utils\format_items( $format, $versions, array( 'Group', 'Version' ) );
	}
}
