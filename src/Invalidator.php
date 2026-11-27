<?php
/**
 * Invalidator — wires WordPress content-change hooks to group version bumps.
 *
 * Cache invalidation strategy: version-bump (not key-delete).
 *
 * Instead of tracking individual cache keys and deleting them one by one
 * on change (which requires a costly secondary index), we keep a small
 * integer version counter per logical group (posts, terms, users, comments).
 * The version is embedded in every cache key built by Key_Builder. When
 * content in a group changes, we increment that counter by 1. All existing
 * keys whose version segment no longer matches are silently unreachable —
 * they will expire naturally at their TTL.
 *
 * Trade-off: stale cached data remains in the store until TTL elapses. For
 * most WordPress sites with a 5-minute TTL this is acceptable; operators
 * who need stricter freshness should lower the TTL via RCI_TTL.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Invalidator
 */
class Invalidator {

	/**
	 * All known cache groups and the hooks that invalidate them.
	 *
	 * @var array<string, array<string, int>>
	 *   Keys: group name. Values: array of hook_name => priority.
	 */
	public const GROUP_HOOKS = array(
		'posts' => array(
			'save_post'                => 10,
			'deleted_post'             => 10,
			'transition_post_status'   => 10,
			'attachment_updated'       => 10,
		),
		'terms' => array(
			'edited_term'              => 10,
			'delete_term'              => 10,
			'set_object_terms'         => 10,
			'created_term'             => 10,
		),
		'users' => array(
			'profile_update'           => 10,
			'user_register'            => 10,
			'deleted_user'             => 10,
		),
		'comments' => array(
			'comment_post'             => 10,
			'edit_comment'             => 10,
			'delete_comment'           => 10,
			'wp_set_comment_status'    => 10,
			'trash_comment'            => 10,
		),
	);

	/**
	 * Flat list of group names (used by stores to iterate).
	 *
	 * @var list<string>
	 */
	public const GROUPS = array( 'posts', 'terms', 'users', 'comments' );

	/**
	 * Active cache store.
	 *
	 * @var Object_Cache_Store|Transient_Store
	 */
	private Object_Cache_Store|Transient_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Cache_Store $store Active cache store.
	 */
	public function __construct( Cache_Store $store ) {
		$this->store = $store; // @phpstan-ignore-line
	}

	/**
	 * Register all invalidation hooks with WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::GROUP_HOOKS as $group => $hooks ) {
			foreach ( $hooks as $hook => $priority ) {
				// Use a closure so we capture $group by value.
				add_action(
					$hook,
					function () use ( $group ): void {
						$this->bump( $group );
					},
					$priority,
					0 // We don't need hook arguments; bump on any call.
				);
			}
		}
	}

	/**
	 * Bump the version counter for a group.
	 *
	 * @param string $group Group name.
	 * @return int New version number.
	 */
	public function bump( string $group ): int {
		return $this->store->bump_group_version( $group );
	}
}
