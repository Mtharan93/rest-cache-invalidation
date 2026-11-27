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
use RestCacheInvalidation\Cache_Store;
use RestCacheInvalidation\Invalidator;

/**
 * Class InvalidatorTest.
 */
class InvalidatorTest extends TestCase {

	/**
	 * Set up Brain\Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub add_action to a no-op (we test bump() directly).
		Functions\when( 'add_action' )->justReturn( true );
	}

	/**
	 * Tear down Brain\Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verify bump() calls bump_group_version on the store.
	 *
	 * @return void
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
	 * Verify GROUPS constant lists the expected group names.
	 *
	 * @return void
	 */
	public function test_groups_constant_contains_expected_groups(): void {
		$this->assertContains( 'posts', Invalidator::GROUPS );
		$this->assertContains( 'terms', Invalidator::GROUPS );
		$this->assertContains( 'users', Invalidator::GROUPS );
		$this->assertContains( 'comments', Invalidator::GROUPS );
	}

	/**
	 * Verify GROUP_HOOKS wires the required WordPress hooks.
	 *
	 * @dataProvider required_hooks_provider
	 *
	 * @param string $group Group name.
	 * @param string $hook  WordPress action hook name.
	 * @return void
	 */
	public function test_required_hooks_are_registered( string $group, string $hook ): void {
		$this->assertArrayHasKey( $hook, Invalidator::GROUP_HOOKS[ $group ] );
	}

	/**
	 * Provide group and hook pairs for registration checks.
	 *
	 * @return array<array{string, string}> List of group and hook pairs.
	 */
	public static function required_hooks_provider(): array {
		return array(
			array( 'posts', 'save_post' ),
			array( 'posts', 'deleted_post' ),
			array( 'posts', 'transition_post_status' ),
			array( 'terms', 'edited_term' ),
			array( 'terms', 'delete_term' ),
			array( 'terms', 'set_object_terms' ),
			array( 'users', 'profile_update' ),
			array( 'comments', 'comment_post' ),
			array( 'comments', 'wp_set_comment_status' ),
		);
	}
}
