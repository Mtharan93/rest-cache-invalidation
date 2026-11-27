<?php
/**
 * Tests for Key_Builder.
 *
 * These are unit tests that run without a full WordPress environment.
 * We use Brain\Monkey to stub WordPress functions.
 *
 * @package RestCacheInvalidation\Tests\Unit
 */

namespace RestCacheInvalidation\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RestCacheInvalidation\Key_Builder;
use RestCacheInvalidation\Cache_Store;

/**
 * Class Key_BuilderTest
 */
class Key_BuilderTest extends TestCase {

	/** @var Cache_Store&\PHPUnit\Framework\MockObject\MockObject */
	private Cache_Store $store;

	/** @var Key_Builder */
	private Key_Builder $builder;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub apply_filters to be transparent.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->store = $this->createMock( Cache_Store::class );
		$this->store->method( 'get_group_version' )->willReturn( 1 );

		$this->builder = new Key_Builder( $this->store );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Keys for the same route but different sorted params must differ.
	 */
	public function test_key_differs_by_query_params(): void {
		$request_a = $this->make_request( '/wp/v2/posts', array( 'per_page' => 10 ) );
		$request_b = $this->make_request( '/wp/v2/posts', array( 'per_page' => 20 ) );

		$key_a = $this->builder->build( $request_a, 'posts' );
		$key_b = $this->builder->build( $request_b, 'posts' );

		$this->assertNotSame( $key_a, $key_b );
	}

	/**
	 * Parameter ORDER must NOT affect the key (?a=1&b=2 == ?b=2&a=1).
	 */
	public function test_key_is_order_independent(): void {
		$request_a = $this->make_request( '/wp/v2/posts', array( 'a' => '1', 'b' => '2' ) );
		$request_b = $this->make_request( '/wp/v2/posts', array( 'b' => '2', 'a' => '1' ) );

		$key_a = $this->builder->build( $request_a, 'posts' );
		$key_b = $this->builder->build( $request_b, 'posts' );

		$this->assertSame( $key_a, $key_b );
	}

	/**
	 * Keys for different routes must differ.
	 */
	public function test_key_differs_by_route(): void {
		$request_posts = $this->make_request( '/wp/v2/posts', array() );
		$request_pages = $this->make_request( '/wp/v2/pages', array() );

		$key_posts = $this->builder->build( $request_posts, 'posts' );
		$key_pages = $this->builder->build( $request_pages, 'posts' );

		$this->assertNotSame( $key_posts, $key_pages );
	}

	/**
	 * When the group version changes the key must change.
	 */
	public function test_key_changes_on_version_bump(): void {
		$store_v1 = $this->createMock( Cache_Store::class );
		$store_v1->method( 'get_group_version' )->willReturn( 1 );

		$store_v2 = $this->createMock( Cache_Store::class );
		$store_v2->method( 'get_group_version' )->willReturn( 2 );

		$builder_v1 = new Key_Builder( $store_v1 );
		$builder_v2 = new Key_Builder( $store_v2 );

		$request = $this->make_request( '/wp/v2/posts', array() );

		$this->assertNotSame(
			$builder_v1->build( $request, 'posts' ),
			$builder_v2->build( $request, 'posts' )
		);
	}

	/**
	 * Key must start with the rci/ prefix.
	 */
	public function test_key_has_expected_prefix(): void {
		$request = $this->make_request( '/wp/v2/posts', array() );
		$key     = $this->builder->build( $request, 'posts' );

		$this->assertStringStartsWith( 'rci/', $key );
	}

	/**
	 * group_for_route routing table tests.
	 *
	 * @dataProvider route_group_provider
	 */
	public function test_group_for_route( string $route, string $expected_group ): void {
		$this->assertSame( $expected_group, $this->builder->group_for_route( $route ) );
	}

	/** @return array<array{string, string}> */
	public static function route_group_provider(): array {
		return array(
			array( '/wp/v2/posts', 'posts' ),
			array( '/wp/v2/posts/42', 'posts' ),
			array( '/wp/v2/pages', 'posts' ),
			array( '/wp/v2/media', 'posts' ),
			array( '/wp/v2/comments', 'comments' ),
			array( '/wp/v2/comments/5', 'comments' ),
			array( '/wp/v2/users', 'users' ),
			array( '/wp/v2/users/me', 'users' ),
			array( '/wp/v2/categories', 'terms' ),
			array( '/wp/v2/tags', 'terms' ),
			array( '/wp/v2/taxonomies', 'terms' ),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a minimal WP_REST_Request stub.
	 *
	 * @param string               $route  Route string.
	 * @param array<string, mixed> $params Query params.
	 * @return \WP_REST_Request&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_request( string $route, array $params ): \WP_REST_Request {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_route' )->willReturn( $route );
		$request->method( 'get_query_params' )->willReturn( $params );
		return $request;
	}
}
