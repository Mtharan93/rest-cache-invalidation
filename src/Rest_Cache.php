<?php
/**
 * Rest_Cache — hooks into the REST API dispatch cycle to serve and store cache.
 *
 * Hook points
 * ───────────
 * rest_pre_dispatch  (priority 10, early)
 *   Called before the REST server routes the request. Returning a non-null
 *   value from this filter short-circuits the actual endpoint handler.
 *   On a cache HIT we return the stored WP_REST_Response directly.
 *
 * rest_post_dispatch (priority 10)
 *   Called after the endpoint handler has run. On a cache MISS for a
 *   cacheable request we store the response body + headers, then set
 *   X-Cache: MISS on the outgoing response.
 *
 * What we cache
 * ─────────────
 *   - Method:    GET only
 *   - Namespace: wp/v2 only (configurable via filter)
 *   - Auth:      never cache authenticated requests (cookie, Authorization
 *                header, nonce query param)
 *   - Status:    200 only
 *
 * Headers added
 * ─────────────
 *   X-Cache: HIT | MISS
 *   X-Cache-TTL: <remaining seconds>   (HIT only)
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Cache
 */
class Rest_Cache {

	/**
	 * Default TTL in seconds (5 minutes). Override with RCI_TTL constant.
	 */
	private const DEFAULT_TTL = 300;

	/**
	 * REST namespace prefix we cache. Extend via filter if needed.
	 */
	private const CACHED_NAMESPACE_PREFIX = 'wp/v2';

	/**
	 * Active cache store.
	 *
	 * @var Cache_Store
	 */
	private Cache_Store $store;

	/**
	 * Key builder.
	 *
	 * @var Key_Builder
	 */
	private Key_Builder $key_builder;

	/**
	 * Store the pending cache key between pre_dispatch and post_dispatch.
	 * Null means the current request is not cacheable or was a HIT.
	 *
	 * @var string|null
	 */
	private ?string $pending_key = null;

	/**
	 * Constructor.
	 *
	 * @param Cache_Store $store       Active cache store.
	 * @param Key_Builder $key_builder Key builder instance.
	 */
	public function __construct( Cache_Store $store, Key_Builder $key_builder ) {
		$this->store       = $store;
		$this->key_builder = $key_builder;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'serve' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'store_response' ), 10, 3 );
	}

	/**
	 * rest_pre_dispatch — serve from cache on HIT.
	 *
	 * @param mixed            $result  Current short-circuit result (null by default).
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Incoming request.
	 * @return mixed WP_REST_Response on HIT, original $result on MISS.
	 */
	public function serve( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		// Another filter already short-circuited; respect that.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $this->is_cacheable_request( $request ) ) {
			return $result;
		}

		$group = $this->key_builder->group_for_route( $request->get_route() );
		$key   = $this->key_builder->build( $request, $group );

		$cached = $this->store->get( $key );

		if ( false === $cached || ! is_array( $cached ) ) {
			// Cache MISS — record key so post_dispatch can store it.
			$this->pending_key = $key;
			$this->store->increment_misses();
			return $result;
		}

		// Cache HIT.
		$this->store->increment_hits();
		$this->pending_key = null;

		$response = new \WP_REST_Response( $cached['body'], $cached['status'] );

		// Restore previously cached headers (content-type, link, etc.).
		if ( ! empty( $cached['headers'] ) && is_array( $cached['headers'] ) ) {
			foreach ( $cached['headers'] as $name => $value ) {
				$response->header( $name, $value );
			}
		}

		// Add cache-status headers.
		$remaining = max( 0, (int) $cached['expires'] - time() );
		$response->header( 'X-Cache', 'HIT' );
		$response->header( 'X-Cache-TTL', (string) $remaining );

		return $response;
	}

	/**
	 * rest_post_dispatch — store a fresh response in the cache on MISS.
	 *
	 * @param \WP_REST_Response $result  Response from the endpoint handler.
	 * @param \WP_REST_Server   $server  REST server instance.
	 * @param \WP_REST_Request  $request The original request.
	 * @return \WP_REST_Response The (possibly header-modified) response.
	 */
	public function store_response( \WP_REST_Response $result, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		// Only store if pre_dispatch set a pending key (cacheable MISS).
		if ( null === $this->pending_key ) {
			return $result;
		}

		// Only cache 200 OK responses.
		if ( 200 !== $result->get_status() ) {
			$this->pending_key = null;
			return $result;
		}

		$ttl = $this->get_ttl( $request );

		// Allow per-request TTL override.
		/**
		 * Filters the cache TTL for this specific request.
		 *
		 * @param int              $ttl     TTL in seconds.
		 * @param \WP_REST_Request $request The current request.
		 */
		$ttl = (int) apply_filters( 'rci_cache_ttl', $ttl, $request );

		if ( $ttl <= 0 ) {
			$this->pending_key = null;
			return $result;
		}

		$headers_to_cache = array();
		foreach ( $result->get_headers() as $name => $value ) {
			// Don't persist vary/cache-control headers from the origin response.
			if ( in_array( strtolower( $name ), array( 'cache-control', 'pragma', 'vary' ), true ) ) {
				continue;
			}
			$headers_to_cache[ $name ] = $value;
		}

		$payload = array(
			'body'    => $result->get_data(),
			'status'  => $result->get_status(),
			'headers' => $headers_to_cache,
			'expires' => time() + $ttl,
		);

		$this->store->set( $this->pending_key, $payload, $ttl );
		$this->pending_key = null;

		$result->header( 'X-Cache', 'MISS' );

		return $result;
	}

	/**
	 * Decide whether a request is eligible for caching.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool True if cacheable, false if not.
	 */
	private function is_cacheable_request( \WP_REST_Request $request ): bool {
		// GET only.
		if ( 'GET' !== $request->get_method() ) {
			return false;
		}

		// wp/v2 namespace only.
		$route = $request->get_route();
		if ( ! str_starts_with( ltrim( $route, '/' ), self::CACHED_NAMESPACE_PREFIX ) ) {
			return false;
		}

		// Never cache authenticated requests.
		if ( $this->is_authenticated( $request ) ) {
			return false;
		}

		/**
		 * Filters whether a specific request should be skipped (not cached).
		 *
		 * Return true to prevent caching this request.
		 *
		 * @param bool             $skip    Whether to skip caching. Default false.
		 * @param \WP_REST_Request $request The current request.
		 */
		if ( (bool) apply_filters( 'rci_cache_skip', false, $request ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether the request carries any authentication signal.
	 *
	 * We err on the side of caution: any hint of authentication bypasses
	 * the cache. This prevents leaking private data into public cache entries.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool True if request appears authenticated.
	 */
	private function is_authenticated( \WP_REST_Request $request ): bool {
		// Cookie-based login (most common on the front-end).
		if ( is_user_logged_in() ) {
			return true;
		}

		// Token / JWT / Application Password.
		$auth_header = $request->get_header( 'authorization' );
		if ( ! empty( $auth_header ) ) {
			return true;
		}

		// WP REST nonce passed as query param or X-WP-Nonce header.
		$params = $request->get_query_params();
		if ( ! empty( $params['_wpnonce'] ) || ! empty( $params['nonce'] ) ) {
			return true;
		}

		if ( ! empty( $request->get_header( 'x-wp-nonce' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve the configured TTL.
	 *
	 * @param \WP_REST_Request $request Incoming request (unused here, available for filter).
	 * @return int TTL in seconds.
	 */
	private function get_ttl( \WP_REST_Request $request ): int {
		if ( defined( 'RCI_TTL' ) && is_int( RCI_TTL ) && RCI_TTL > 0 ) {
			return RCI_TTL;
		}

		return self::DEFAULT_TTL;
	}
}
