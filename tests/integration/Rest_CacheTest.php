<?php
/**
 * Integration tests for Rest_Cache.
 *
 * These run inside the full WordPress testing environment (via wp-env).
 * We exercise the REST API dispatch pipeline end-to-end: miss → hit,
 * headers, auth bypass, non-200 bypass, skip-filter, and TTL.
 *
 * @package RestCacheInvalidation\Tests\Integration
 */

namespace RestCacheInvalidation\Tests\Integration;

use RestCacheInvalidation\Key_Builder;
use RestCacheInvalidation\Rest_Cache;
use RestCacheInvalidation\Transient_Store;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class Rest_CacheTest
 *
 * @group integration
 */
class Rest_CacheTest extends \WP_Test_REST_TestCase {

	/** @var Transient_Store */
	private Transient_Store $store;

	/** @var Key_Builder */
	private Key_Builder $key_builder;

	/** @var Rest_Cache */
	private Rest_Cache $rest_cache;

	protected function setUp(): void {
		parent::setUp();

		$this->store       = new Transient_Store();
		$this->key_builder = new Key_Builder( $this->store );
		$this->rest_cache  = new Rest_Cache( $this->store, $this->key_builder );
		$this->rest_cache->register();
	}

	protected function tearDown(): void {
		$this->store->flush();
		remove_all_filters( 'rest_cache_skip' );
		remove_all_filters( 'rest_cache_key' );
		remove_all_filters( 'rest_cache_ttl' );
		parent::tearDown();
	}

	// ── Miss → Hit ────────────────────────────────────────────────────────────

	/**
	 * First request: MISS header. Second identical request: HIT header.
	 */
	public function test_miss_then_hit(): void {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'MISS', $response->get_headers()['X-Cache'] ?? null, 'First request should be MISS' );

		$response2 = $this->server->dispatch( $request );
		$this->assertSame( 'HIT', $response2->get_headers()['X-Cache'] ?? null, 'Second request should be HIT' );
	}

	/**
	 * X-Cache-TTL header must be present on a HIT and be a non-negative integer.
	 */
	public function test_hit_includes_ttl_header(): void {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->server->dispatch( $request ); // prime MISS

		$response = $this->server->dispatch( $request ); // HIT
		$ttl      = $response->get_headers()['X-Cache-TTL'] ?? null;

		$this->assertNotNull( $ttl );
		$this->assertGreaterThanOrEqual( 0, (int) $ttl );
	}

	// ── Key uniqueness ────────────────────────────────────────────────────────

	/**
	 * Different query params must produce a MISS on the second request.
	 */
	public function test_different_query_params_produce_cache_miss(): void {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );

		$req1 = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$req1->set_query_params( array( 'per_page' => 5 ) );
		$this->server->dispatch( $req1 );

		$req2 = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$req2->set_query_params( array( 'per_page' => 10 ) );
		$resp2 = $this->server->dispatch( $req2 );

		$this->assertSame( 'MISS', $resp2->get_headers()['X-Cache'] ?? null );
	}

	// ── Auth bypass ───────────────────────────────────────────────────────────

	/**
	 * Requests from a logged-in user must never be cached.
	 */
	public function test_authenticated_requests_are_not_cached(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = $this->server->dispatch( $request );

		$this->assertArrayNotHasKey( 'X-Cache', $response->get_headers() );

		wp_set_current_user( 0 );
	}

	/**
	 * Requests with a nonce query param must not be cached.
	 */
	public function test_nonce_in_query_bypasses_cache(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_query_params( array( '_wpnonce' => 'fake_nonce_value' ) );
		$response = $this->server->dispatch( $request );

		$this->assertArrayNotHasKey( 'X-Cache', $response->get_headers() );
	}

	// ── Non-GET bypass ────────────────────────────────────────────────────────

	/**
	 * POST requests must never be cached.
	 */
	public function test_post_method_is_not_cached(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params( array(
			'title'  => 'Test Post',
			'status' => 'publish',
		) );

		$response = $this->server->dispatch( $request );

		$this->assertArrayNotHasKey( 'X-Cache', $response->get_headers() );

		wp_set_current_user( 0 );
	}

	// ── Non-200 bypass ────────────────────────────────────────────────────────

	/**
	 * Non-200 responses (e.g. 404) must not be cached.
	 */
	public function test_404_response_is_not_cached(): void {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/999999' );
		$response = $this->server->dispatch( $request );

		// 404 should not write X-Cache header or cache the entry.
		$headers = $response->get_headers();
		// Either no X-Cache header, or X-Cache: MISS but no second HIT stored.
		if ( isset( $headers['X-Cache'] ) && 'MISS' === $headers['X-Cache'] ) {
			// The response was categorised as a MISS but status is 404 —
			// post_dispatch must have aborted storage. Verify by re-requesting.
			$response2 = $this->server->dispatch( $request );
			// Should still be MISS, never HIT.
			$this->assertNotSame( 'HIT', $response2->get_headers()['X-Cache'] ?? 'MISS' );
		} else {
			// No X-Cache header at all — also acceptable.
			$this->assertArrayNotHasKey( 'X-Cache', $headers );
		}
	}

	// ── skip filter ───────────────────────────────────────────────────────────

	/**
	 * When rest_cache_skip returns true, the response must not be cached.
	 */
	public function test_skip_filter_prevents_caching(): void {
		add_filter( 'rest_cache_skip', '__return_true' );

		$this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = $this->server->dispatch( $request );

		$this->assertArrayNotHasKey( 'X-Cache', $response->get_headers() );
	}

	// ── Invalidation ─────────────────────────────────────────────────────────

	/**
	 * After save_post, a previously cached posts response must be a MISS.
	 */
	public function test_cache_invalidated_after_save_post(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->server->dispatch( $request ); // MISS — primes cache.

		$resp_hit = $this->server->dispatch( $request );
		$this->assertSame( 'HIT', $resp_hit->get_headers()['X-Cache'] ?? null );

		// Trigger invalidation.
		do_action( 'save_post', $post_id, get_post( $post_id ), true );

		// Cache version bumped — next request is a MISS again.
		$resp_miss = $this->server->dispatch( $request );
		$this->assertSame( 'MISS', $resp_miss->get_headers()['X-Cache'] ?? null );
	}

	/**
	 * After deleted_post, cached response becomes a MISS.
	 */
	public function test_cache_invalidated_after_deleted_post(): void {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->server->dispatch( $request );
		$this->assertSame( 'HIT', $this->server->dispatch( $request )->get_headers()['X-Cache'] ?? null );

		do_action( 'deleted_post', $post_id, get_post( $post_id ) );

		$this->assertSame( 'MISS', $this->server->dispatch( $request )->get_headers()['X-Cache'] ?? null );
	}

	/**
	 * After edited_term, cached terms response becomes a MISS.
	 */
	public function test_cache_invalidated_after_term_change(): void {
		$term = $this->factory->term->create( array( 'taxonomy' => 'category' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/categories' );
		$this->server->dispatch( $request );
		$this->assertSame( 'HIT', $this->server->dispatch( $request )->get_headers()['X-Cache'] ?? null );

		do_action( 'edited_term', $term, get_term( $term )->term_taxonomy_id, 'category' );

		$this->assertSame( 'MISS', $this->server->dispatch( $request )->get_headers()['X-Cache'] ?? null );
	}
}
