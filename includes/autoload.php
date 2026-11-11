<?php
/**
 * Class autoloader for the plugin's own classes (no runtime dependencies).
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Bristlecone\\Zfolio\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		if ( str_starts_with( $relative, 'Tests\\' ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
