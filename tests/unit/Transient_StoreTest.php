<?php
/**
 * Tests for Transient_Store.
 *
 * Unit tests using Brain\Monkey to stub WordPress functions.
 *
 * @package RestCacheInvalidation\Tests\Unit
 */

namespace RestCacheInvalidation\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RestCacheInvalidation\Transient_Store;

/**
 * Class Transient_StoreTest.
 */
class Transient_StoreTest extends TestCase {

	/**
	 * Set up Brain\Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Count Mockery expectations as assertions and tear down Brain\Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$container = \Mockery::getContainer();
		if ( null !== $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verify get() returns false on a cache miss.
	 *
	 * @return void
	 */
	public function test_get_returns_false_on_miss(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'rcin_c_mykey' )
			->andReturn( false );

		$store = new Transient_Store();
		$this->assertFalse( $store->get( 'mykey' ) );
	}

	/**
	 * Verify set() calls set_transient() with the expected key prefix.
	 *
	 * @return void
	 */
	public function test_set_calls_set_transient_with_prefix(): void {
		Functions\expect( 'set_transient' )
			->once()
			->with( 'rcin_c_mykey', array( 'body' => 'hello' ), 300 )
			->andReturn( true );

		$store  = new Transient_Store();
		$result = $store->set( 'mykey', array( 'body' => 'hello' ), 300 );
		$this->assertTrue( $result );
	}

	/**
	 * Verify delete() calls delete_transient() with the prefixed key.
	 *
	 * @return void
	 */
	public function test_delete_calls_delete_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'rcin_c_mykey' )
			->andReturn( true );

		$store = new Transient_Store();
		$this->assertTrue( $store->delete( 'mykey' ) );
	}

	/**
	 * Verify increment_hits() updates the hits counter in options.
	 *
	 * @return void
	 */
	public function test_increment_hits_updates_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rcin_stats', array() )
			->andReturn(
				array(
					'hits'   => 5,
					'misses' => 2,
				)
			);

		Functions\expect( 'update_option' )
			->once()
			->with(
				'rcin_stats',
				array(
					'hits'   => 6,
					'misses' => 2,
				),
				false
			)
			->andReturn( true );

		$store = new Transient_Store();
		$store->increment_hits();
	}

	/**
	 * Verify increment_misses() updates the misses counter in options.
	 *
	 * @return void
	 */
	public function test_increment_misses_updates_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rcin_stats', array() )
			->andReturn(
				array(
					'hits'   => 0,
					'misses' => 3,
				)
			);

		Functions\expect( 'update_option' )
			->once()
			->with(
				'rcin_stats',
				array(
					'hits'   => 0,
					'misses' => 4,
				),
				false
			)
			->andReturn( true );

		$store = new Transient_Store();
		$store->increment_misses();
	}

	/**
	 * Verify bump_group_version() increments the stored option counter.
	 *
	 * @return void
	 */
	public function test_bump_group_version_increments_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rcin_v_posts', 1 )
			->andReturn( 3 );

		Functions\expect( 'update_option' )
			->once()
			->with( 'rcin_v_posts', 4, false )
			->andReturn( true );

		$store = new Transient_Store();
		$next  = $store->bump_group_version( 'posts' );

		$this->assertSame( 4, $next );
	}

	/**
	 * Verify get_group_version() defaults to 1 when the option is missing.
	 *
	 * @return void
	 */
	public function test_get_group_version_defaults_to_1_on_missing_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rcin_v_posts', 1 )
			->andReturn( false );

		$store = new Transient_Store();
		$this->assertSame( 1, $store->get_group_version( 'posts' ) );
	}
}
