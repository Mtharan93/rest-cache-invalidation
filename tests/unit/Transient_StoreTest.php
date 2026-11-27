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
 * Class Transient_StoreTest
 */
class Transient_StoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_returns_false_on_miss(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'rci_c_mykey' )
			->andReturn( false );

		$store = new Transient_Store();
		$this->assertFalse( $store->get( 'mykey' ) );
	}

	public function test_set_calls_set_transient_with_prefix(): void {
		Functions\expect( 'set_transient' )
			->once()
			->with( 'rci_c_mykey', array( 'body' => 'hello' ), 300 )
			->andReturn( true );

		$store  = new Transient_Store();
		$result = $store->set( 'mykey', array( 'body' => 'hello' ), 300 );
		$this->assertTrue( $result );
	}

	public function test_delete_calls_delete_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'rci_c_mykey' )
			->andReturn( true );

		$store = new Transient_Store();
		$this->assertTrue( $store->delete( 'mykey' ) );
	}

	public function test_increment_hits_updates_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rci_stats', array() )
			->andReturn( array( 'hits' => 5, 'misses' => 2 ) );

		Functions\expect( 'update_option' )
			->once()
			->with( 'rci_stats', array( 'hits' => 6, 'misses' => 2 ), false )
			->andReturn( true );

		$store = new Transient_Store();
		$store->increment_hits();
	}

	public function test_increment_misses_updates_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rci_stats', array() )
			->andReturn( array( 'hits' => 0, 'misses' => 3 ) );

		Functions\expect( 'update_option' )
			->once()
			->with( 'rci_stats', array( 'hits' => 0, 'misses' => 4 ), false )
			->andReturn( true );

		$store = new Transient_Store();
		$store->increment_misses();
	}

	public function test_bump_group_version_increments_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rci_v_posts', 1 )
			->andReturn( 3 );

		Functions\expect( 'update_option' )
			->once()
			->with( 'rci_v_posts', 4, false )
			->andReturn( true );

		$store = new Transient_Store();
		$next  = $store->bump_group_version( 'posts' );

		$this->assertSame( 4, $next );
	}

	public function test_get_group_version_defaults_to_1_on_missing_option(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'rci_v_posts', 1 )
			->andReturn( false );

		$store = new Transient_Store();
		$this->assertSame( 1, $store->get_group_version( 'posts' ) );
	}
}
