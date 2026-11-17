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
	 * Connections shown by widgets, which are not posts.
	 */
	public const WIDGETS = 'bristlecone_zfolio_widget_refs';

	/**
	 * Widget options that can hold a block or shortcode.
	 */
	private const WIDGET_OPTIONS = array( 'widget_block', 'widget_text', 'widget_custom_html' );

	/**
	 * Link targets for this request, by connection.
	 *
	 * @var array<string,array<string,int>>
	 */
	private static array $targets = array();

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'record' ), 10, 2 );

		foreach ( self::WIDGET_OPTIONS as $option ) {
			add_action( 'add_option_' . $option, array( self::class, 'record_widgets' ) );
			add_action( 'update_option_' . $option, array( self::class, 'record_widgets' ) );
		}
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
	 * Record which connections widgets show. Widgets have no URL of their own,
	 * so a change to one of these connections purges whole page caches.
	 */
	public static function record_widgets(): void {
		$connections = array();

		foreach ( self::WIDGET_OPTIONS as $option ) {
			foreach ( (array) get_option( $option, array() ) as $widget ) {
				foreach ( is_array( $widget ) ? $widget : array() as $value ) {
					if ( is_string( $value ) ) {
						foreach ( self::find( $value ) as $ref ) {
							$connections[] = strtok( $ref, '|' );
						}
					}
				}
			}
		}

		update_option( self::WIDGETS, array_values( array_unique( $connections ) ), false );
	}

	/**
	 * Whether caching for a connection can't be purged post by post: its
	 * content sits in a widget, a synced pattern, or a template.
	 *
	 * @param string    $connection_id Connection id.
	 * @param list<int> $post_ids      Posts that reference it.
	 */
	public static function needs_full_purge( string $connection_id, array $post_ids ): bool {
		if ( in_array( $connection_id, (array) get_option( self::WIDGETS, array() ), true ) ) {
			return true;
		}

		foreach ( $post_ids as $post_id ) {
			$type = get_post_type( $post_id );

			if ( false === $type || ! is_post_type_viewable( $type ) ) {
				return true;
			}
		}

		return false;
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
	 * Every post type that can hold a reference, synced patterns and
	 * templates included ('any' leaves those out).
	 *
	 * @return list<string>
	 */
	private static function types(): array {
		return array_values( array_diff( get_post_types(), array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ) ) );
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
				'post_type'              => self::types(),
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
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
	 * Posts that show any of some pages, in one query.
	 *
	 * @param string       $connection_id Connection id.
	 * @param list<string> $page_ids      Page ids.
	 * @return list<int>
	 */
	public static function posts_for_pages( string $connection_id, array $page_ids ): array {
		if ( ! $page_ids ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => self::types(),
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META,
						'value'   => array_map( static fn ( string $page_id ): string => $connection_id . '|' . $page_id, $page_ids ),
						'compare' => 'IN',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The post a link to each page points at: the oldest published post of a
	 * public type that shows it. One query per connection per request.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,int> Post id by page id.
	 */
	public static function link_targets( string $connection_id ): array {
		if ( isset( self::$targets[ $connection_id ] ) ) {
			return self::$targets[ $connection_id ];
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.meta_value AS ref, p.ID AS id, p.post_type AS type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_status = 'publish' ORDER BY p.post_date ASC, p.ID ASC",
				self::META,
				$wpdb->esc_like( $connection_id . '|' ) . '%'
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$page_id = substr( (string) $row['ref'], strlen( $connection_id ) + 1 );

			if ( ! isset( $out[ $page_id ] ) && is_post_type_viewable( (string) $row['type'] ) ) {
				$out[ $page_id ] = (int) $row['id'];
			}
		}

		self::$targets[ $connection_id ] = $out;

		return $out;
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
				'post_type'              => self::types(),
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
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
