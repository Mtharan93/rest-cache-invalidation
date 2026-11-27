<?php
/**
 * Store_Factory — selects the right Cache_Store implementation at runtime.
 *
 * Uses the persistent object cache when wp_using_ext_object_cache() returns
 * true; falls back to WordPress transients otherwise.
 *
 * @package RestCacheInvalidation
 */

namespace RestCacheInvalidation;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Store_Factory
 */
class Store_Factory {

	/**
	 * Build and return the appropriate Cache_Store implementation.
	 *
	 * @return Cache_Store Active cache store instance.
	 */
	public static function make(): Cache_Store {
		if ( wp_using_ext_object_cache() ) {
			return new Object_Cache_Store();
		}

		return new Transient_Store();
	}
}
