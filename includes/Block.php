<?php
/**
 * The zFolio Page block.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * A dynamic block: the editor script is plain wp.* JavaScript with no build
 * step, and the page is rendered on the server from stored content, so a
 * zFolio update shows without resaving the post.
 */
final class Block {

	public const NAME   = 'bristlecone-zfolio/page';
	public const SCRIPT = 'bristlecone-zfolio-page-editor';

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Register the editor script and the block.
	 */
	public static function register_block(): void {
		wp_register_script(
			self::SCRIPT,
			BRISTLECONE_ZFOLIO_URL . 'blocks/page/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n', 'wp-url' ),
			BRISTLECONE_ZFOLIO_VERSION,
			true
		);

		wp_set_script_translations( self::SCRIPT, 'bristlecone-zfolio' );

		register_block_type(
			BRISTLECONE_ZFOLIO_DIR . 'blocks/page',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Server render.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$connection = sanitize_key( (string) ( $attributes['connection'] ?? '' ) );
		$page       = sanitize_text_field( (string) ( $attributes['page'] ?? '' ) );
		$inner      = Render::page( $connection, $page );

		if ( '' === $inner ) {
			return '';
		}

		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $inner );
	}
}
