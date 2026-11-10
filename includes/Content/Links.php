<?php
/**
 * Rewrites links between pages of the same feed.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Content;

use WP_HTML_Tag_Processor;

/**
 * Pages link to each other as <a href="#" data-zfolio-slug="…">. At render
 * time each becomes the WordPress address of that page, from a resolver. A
 * link with no address on this site keeps its text and loses its href.
 */
final class Links {

	/**
	 * Rewrite feed links.
	 *
	 * @param string                     $html     Sanitized HTML.
	 * @param callable(string):?string $resolver Slug => URL, or null.
	 */
	public static function rewrite( string $html, callable $resolver ): string {
		if ( ! str_contains( $html, 'data-zfolio-slug' ) ) {
			return $html;
		}

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'a' ) ) {
			$slug = $tags->get_attribute( 'data-zfolio-slug' );

			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$url     = $resolver( $slug );
			$heading = $tags->get_attribute( 'data-zfolio-heading' );

			if ( null === $url ) {
				$tags->remove_attribute( 'href' );
				continue;
			}

			if ( is_string( $heading ) && str_starts_with( $heading, 'user-content-' ) ) {
				$url = strtok( $url, '#' ) . '#' . rawurlencode( $heading );
			}

			$tags->set_attribute( 'href', esc_url( $url ) );
		}

		return $tags->get_updated_html();
	}
}
