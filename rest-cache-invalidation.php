<?php
/**
 * Plugin Name:       REST Cache Invalidation
 * Plugin URI:        https://github.com/your-username/rest-cache-invalidation
 * Description:       Caches public WordPress REST API GET responses and invalidates them correctly when content changes.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rest-cache-invalidation
 *
 * @package RestCacheInvalidation
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RCIN_VERSION', '0.1.0' );
define( 'RCIN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCIN_PLUGIN_FILE', __FILE__ );

/**
 * PSR-4 autoloader for the RestCacheInvalidation namespace.
 *
 * Maps RestCacheInvalidation\Foo\Bar → src/Foo/Bar.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'RestCacheInvalidation\\';
		$base_dir = RCIN_PLUGIN_DIR . 'src/';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Bootstrap the plugin after all plugins are loaded so object-cache status
 * is reliable (persistent object cache plugins register themselves on
 * muplugins_loaded / plugins_loaded).
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Allow disabling the plugin entirely via constant.
		if ( defined( 'RCIN_ENABLED' ) && ! RCIN_ENABLED ) {
			return;
		}

		$store       = \RestCacheInvalidation\Store_Factory::make();
		$key_builder = new \RestCacheInvalidation\Key_Builder( $store );
		$rest_cache  = new \RestCacheInvalidation\Rest_Cache( $store, $key_builder );
		$invalidator = new \RestCacheInvalidation\Invalidator( $store );

		$rest_cache->register();
		$invalidator->register();

		// WP-CLI command.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'rest-cache',
				new \RestCacheInvalidation\CLI\Command( $store )
			);
		}
	}
);
