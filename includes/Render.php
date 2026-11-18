<?php
/**
 * Renders a stored page for visitors.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

use Bristlecone\Zfolio\Content\Links;
use Bristlecone\Zfolio\Content\Sanitizer;

/**
 * Shared by the block and the shortcode. Content always comes from the
 * database; a visitor's request never reaches zFolio.
 */
final class Render {

	/**
	 * Resolved URLs for this request, keyed by "connection|slug".
	 *
	 * @var array<string,?string>
	 */
	private static array $urls = array();

	/**
	 * Slug maps for this request, by connection.
	 *
	 * @var array<string,array<string,string>>
	 */
	private static array $slugs = array();

	/**
	 * A connection's slug to page id map, read once per request.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,string>
	 */
	private static function slugs( string $connection_id ): array {
		return self::$slugs[ $connection_id ] ??= Store::slugs( $connection_id );
	}

	/**
	 * HTML for one page.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $page          Page id or slug.
	 * @param string $class         Extra wrapper classes.
	 */
	public static function page( string $connection_id, string $page, string $class = '' ): string {
		$connection = Connections::get( $connection_id );

		if ( null === $connection || '' === $page ) {
			return self::editor_note( __( 'Choose a zFolio connection and page.', 'bristlecone-zfolio' ) );
		}

		$state = Connections::state( $connection_id );

		if ( in_array( $state['status'], array( 'paused', 'revoked' ), true ) && 'hide' === $connection['takedown'] ) {
			return self::editor_note( __( 'This zFolio feed is paused or turned off, so this content is hidden.', 'bristlecone-zfolio' ) );
		}

		$row = Store::get( $connection_id, $page );

		if ( null === $row || '' === (string) $row['html'] ) {
			return self::editor_note( __( 'This zFolio page hasn’t been fetched yet.', 'bristlecone-zfolio' ) );
		}

		$html = (string) $row['html'];

		if ( (int) $row['sanitizer'] < Sanitizer::GENERATION ) {
			// Cleaned by an older sanitizer: clean again once, and keep the result.
			$html = Sanitizer::clean( $html );
			Store::save_recleaned( $connection_id, (string) $row['page_id'], $html );
		}

		if ( 'tex' === $connection['math'] ) {
			$html = Sanitizer::strip_math( $html );
		}

		$html = Links::rewrite(
			$html,
			static fn ( string $slug ): ?string => self::url_for( $connection_id, $slug )
		);

		// Feed text is never shortcode syntax: `the_content` runs shortcodes after blocks.
		$html = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html );

		$classes = trim( 'zfolio-page ' . $class );
		$lang    = (string) $row['lang'];

		return sprintf(
			'<div class="%1$s" data-zfolio-page="%2$s"%3$s>%4$s</div>',
			esc_attr( $classes ),
			esc_attr( (string) $row['page_id'] ),
			'' !== $lang ? ' lang="' . esc_attr( $lang ) . '"' : '',
			$html // Sanitized with the bundled allowlist when stored.
		);
	}

	/**
	 * Where a page of a connection is shown on this site, if anywhere.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $slug          Page slug.
	 */
	public static function url_for( string $connection_id, string $slug ): ?string {
		$key = $connection_id . '|' . $slug;

		if ( array_key_exists( $key, self::$urls ) ) {
			return self::$urls[ $key ];
		}

		$url     = null;
		$page_id = self::slugs( $connection_id )[ $slug ] ?? null;

		if ( null !== $page_id ) {
			$post_id = Refs::link_targets( $connection_id )[ $page_id ] ?? 0;

			if ( $post_id > 0 ) {
				$permalink = get_permalink( $post_id );
				$url       = is_string( $permalink ) ? $permalink : null;
			}
		}

		/**
		 * Filters the address a link to another feed page points at.
		 *
		 * @param string|null $url           URL, or null for no link.
		 * @param string      $connection_id Connection id.
		 * @param string      $slug          Page slug.
		 */
		$url = apply_filters( 'bristlecone_zfolio_page_url', $url, $connection_id, $slug );

		self::$urls[ $key ] = is_string( $url ) && '' !== $url ? $url : null;

		return self::$urls[ $key ];
	}

	/**
	 * A note only editors see; visitors get nothing.
	 *
	 * @param string $text Note.
	 */
	private static function editor_note( string $text ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<p class="zfolio-note"><em>' . esc_html( $text ) . '</em></p>';
	}
}
