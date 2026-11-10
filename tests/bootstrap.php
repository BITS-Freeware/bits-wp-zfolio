<?php
/**
 * Test bootstrap: loads the small part of WordPress the plugin's pure classes
 * use (hooks, formatting, kses, the HTML API) without a database.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

$wp = dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/';

define( 'ABSPATH', $wp );
define( 'WPINC', 'wp-includes' );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'BRISTLECONE_ZFOLIO_VERSION', '0.0.0-test' );

require dirname( __DIR__ ) . '/tests/Support/stubs.php';
require $wp . 'wp-includes/plugin.php';
require $wp . 'wp-includes/formatting.php';
require $wp . 'wp-includes/kses.php';

foreach ( array( 'span', 'text-replacement', 'attribute-token', 'tag-processor' ) as $html_api ) {
	require $wp . 'wp-includes/html-api/class-wp-html-' . $html_api . '.php';
}

require dirname( __DIR__ ) . '/includes/autoload.php';
