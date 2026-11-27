<?php
/**
 * Key_Builder — builds deterministic cache keys for REST API responses.
 *
 * Key structure:
 *   rcin/{group_version}/{namespace_hash}/{route_hash}/{params_hash}
 *
 * - group_version  : integer fetched from the store; bumping it makes all old
 *                    keys for that group permanently unreachable.
 * - namespace_hash : MD5 of the REST namespace (e.g. "wp/v2").
 * - route_hash     : MD5 of the matched route (e.g. "/wp/v2/posts/42").
 * - params_hash    : MD5 of alphabetically sorted, URL-encoded query params
 *                    so ?b=2&a=1 and ?a=1&b=2 produce the same key.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Key_Builder.
 */
class Key_Builder {

	/**
	 * The active cache store (used to read the current group version).
	 *
	 * @var Cache_Store
	 */
	private Cache_Store $store;

	/**
	 * Constructor.
	 *
	 * @param Cache_Store $store The active cache store implementation.
	 */
	public function __construct( Cache_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Build a cache key for a REST request.
	 *
	 * Applies the `rcin_cache_key` filter so site operators can customise the
	 * key (e.g. add a locale segment for multilingual sites).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @param string           $group   Cache group ('posts', 'terms', …).
	 * @return string The cache key.
	 */
	public function build( \WP_REST_Request $request, string $group ): string {
		$version   = $this->store->get_group_version( $group );
		$route     = $request->get_route();
		$namespace = $this->extract_namespace( $route );

		// Sort params alphabetically so parameter order does not affect key.
		$params = $request->get_query_params();
		ksort( $params );

		$key = sprintf(
			'rcin/%d/%s/%s/%s',
			$version,
			md5( $namespace ),
			md5( $route ),
			md5( http_build_query( $params ) )
		);

		/**
		 * Filters the computed cache key.
		 *
		 * Use this to add segments (e.g. locale, A/B variant) without
		 * modifying the core key logic.
		 *
		 * @param string           $key     The computed key.
		 * @param \WP_REST_Request $request The current request.
		 * @param string           $group   The cache group.
		 */
		return (string) apply_filters( 'rcin_cache_key', $key, $request, $group );
	}

	/**
	 * Determine which invalidation group owns this route.
	 *
	 * Routing table (first match wins):
	 *   /wp/v2/posts*       → posts
	 *   /wp/v2/pages*       → posts   (pages are post-type)
	 *   /wp/v2/comments*    → comments
	 *   /wp/v2/users*       → users
	 *   /wp/v2/tags*        → terms
	 *   /wp/v2/categories*  → terms
	 *   /wp/v2/taxonomies*  → terms
	 *   /wp/v2/terms*       → terms
	 *   everything else     → posts   (safe default)
	 *
	 * @param string $route Matched route string.
	 * @return string Group name.
	 */
	public function group_for_route( string $route ): string {
		$patterns = array(
			'#^/wp/v\d+/comments#'                         => 'comments',
			'#^/wp/v\d+/users#'                            => 'users',
			'#^/wp/v\d+/(tags|categories|taxonomies|terms)#' => 'terms',
		);

		foreach ( $patterns as $pattern => $group ) {
			if ( preg_match( $pattern, $route ) ) {
				return $group;
			}
		}

		// Posts is the default — covers /posts, /pages, /media, /types, etc.
		return 'posts';
	}

	/**
	 * Extract the namespace segment from a route.
	 *
	 * Example: "/wp/v2/posts/42" → "wp/v2".
	 *
	 * @param string $route Full route string including leading slash.
	 * @return string Namespace string.
	 */
	private function extract_namespace( string $route ): string {
		// Strip leading slash, then take first two path segments.
		$parts = explode( '/', ltrim( $route, '/' ), 4 );
		return implode( '/', array_slice( $parts, 0, 2 ) );
	}
}
