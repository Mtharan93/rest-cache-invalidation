<?php
/**
 * Tests for Invalidator.
 *
 * Unit tests using Brain\Monkey to stub WordPress functions.
 *
 * @package RestCacheInvalidation\Tests\Unit
 */

namespace RestCacheInvalidation\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RestCacheInvalidation\Invalidator;
use RestCacheInvalidation\Cache_Store;

/**
 * Class InvalidatorTest
 */
class InvalidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub add_action to a no-op (we test bump() directly).
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * bump() must call bump_group_version on the store.
	 */
	public function test_bump_calls_store(): void {
		$store = $this->createMock( Cache_Store::class );
		$store->expects( $this->once() )
			->method( 'bump_group_version' )
			->with( 'posts' )
			->willReturn( 2 );

		$invalidator = new Invalidator( $store );
		$result      = $invalidator->bump( 'posts' );

		$this->assertSame( 2, $result );
	}

	/**
	 * GROUPS constant must list the expected group names.
	 */
	public function test_groups_constant_contains_expected_groups(): void {
		$this->assertContains( 'posts', Invalidator::GROUPS );
		$this->assertContains( 'terms', Invalidator::GROUPS );
		$this->assertContains( 'users', Invalidator::GROUPS );
		$this->assertContains( 'comments', Invalidator::GROUPS );
	}

	/**
	 * GROUP_HOOKS must wire the required WordPress hooks.
	 *
	 * @dataProvider required_hooks_provider
	 */
	public function test_required_hooks_are_registered( string $group, string $hook ): void {
		$this->assertArrayHasKey( $hook, Invalidator::GROUP_HOOKS[ $group ] );
	}

	/** @return array<array{string, string}> */
	public static function required_hooks_provider(): array {
		return array(
			array( 'posts',    'save_post' ),
			array( 'posts',    'deleted_post' ),
			array( 'posts',    'transition_post_status' ),
			array( 'terms',    'edited_term' ),
			array( 'terms',    'delete_term' ),
			array( 'terms',    'set_object_terms' ),
			array( 'users',    'profile_update' ),
			array( 'comments', 'comment_post' ),
			array( 'comments', 'wp_set_comment_status' ),
		);
	}
}
