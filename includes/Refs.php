<?php
/**
 * Tracks which posts show which feed pages.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * When a post is saved, the plugin records each zFolio page it embeds (block
 * or shortcode) as post meta. That tells the plugin which posts to purge from
 * caches when a page changes, and where to point links between pages.
 */
final class Refs {

	public const META = '_bristlecone_zfolio_ref';

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'record' ), 10, 2 );
	}

	/**
	 * Record a post's references.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public static function record( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}

		delete_post_meta( (int) $post_id, self::META );

		foreach ( self::find( (string) $post->post_content ) as $ref ) {
			add_post_meta( (int) $post_id, self::META, $ref );
		}
	}

	/**
	 * References in post content, as "connection|page id".
	 *
	 * @param string $content Post content.
	 * @return list<string>
	 */
	public static function find( string $content ): array {
		$refs = array();

		if ( has_blocks( $content ) ) {
			self::walk_blocks( parse_blocks( $content ), $refs );
		}

		if ( str_contains( $content, '[zfolio' ) && preg_match_all( '/' . get_shortcode_regex( array( Shortcode::TAG ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts = shortcode_parse_atts( $match[3] );
				$ref  = self::ref( (string) ( $atts['connection'] ?? '' ), (string) ( $atts['page'] ?? '' ) );

				if ( '' !== $ref ) {
					$refs[] = $ref;
				}
			}
		}

		return array_values( array_unique( $refs ) );
	}

	/**
	 * Posts that show a page.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $page_id       Page id.
	 * @return list<int>
	 */
	public static function posts_for( string $connection_id, string $page_id ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 50,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META,
						'value' => $connection_id . '|' . $page_id,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Posts that show any page of a connection.
	 *
	 * @param string $connection_id Connection id.
	 * @return list<int>
	 */
	public static function posts_for_connection( string $connection_id ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 200,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META,
						'value'   => $connection_id . '|',
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Walk blocks, inner blocks included.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param list<string>                   $refs   Collected refs.
	 */
	private static function walk_blocks( array $blocks, array &$refs ): void {
		foreach ( $blocks as $block ) {
			if ( Block::NAME === ( $block['blockName'] ?? '' ) ) {
				$attrs = (array) ( $block['attrs'] ?? array() );
				$ref   = self::ref( (string) ( $attrs['connection'] ?? '' ), (string) ( $attrs['page'] ?? '' ) );

				if ( '' !== $ref ) {
					$refs[] = $ref;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk_blocks( (array) $block['innerBlocks'], $refs );
			}
		}
	}

	/**
	 * Normalize a reference, resolving a slug to its page id.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $page          Page id or slug.
	 */
	private static function ref( string $connection_id, string $page ): string {
		if ( ! Connections::valid_id( $connection_id ) || '' === $page ) {
			return '';
		}

		if ( ! str_starts_with( $page, 'pg_' ) ) {
			$row = Store::get( $connection_id, $page );

			if ( null === $row ) {
				return '';
			}

			$page = (string) $row['page_id'];
		}

		return $connection_id . '|' . $page;
	}
}
