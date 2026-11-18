<?php
/**
 * The [zfolio] shortcode.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * [zfolio connection="c123456789" page="pg_… or slug"]
 */
final class Shortcode {

	// The tag is the product's name, [zfolio], on purpose: it is what site
	// owners type. Everything stored or hooked uses the bristlecone_zfolio prefix.

	public const TAG = 'zfolio';

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'connection' => '',
				'page'       => '',
				'class'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$classes = preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY );

		return Render::page(
			sanitize_key( $atts['connection'] ),
			sanitize_text_field( $atts['page'] ),
			implode( ' ', array_map( 'sanitize_html_class', false === $classes ? array() : $classes ) )
		);
	}
}
