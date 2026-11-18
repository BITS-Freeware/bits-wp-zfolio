<?php
/**
 * Mirror mode: keep a connection's pages as read-only WordPress pages.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Mirror;

use Bristlecone\Zfolio\Cache;
use Bristlecone\Zfolio\Connections;
use Bristlecone\Zfolio\Render;
use Bristlecone\Zfolio\Store;
use WP_Post;

/**
 * Mirrored pages are posts of a hierarchical post type, one per folder and
 * page, under a root post per connection. Their content always comes from the
 * stored page at render time; the post holds plain text only so site search
 * finds it. Nobody edits them in WordPress: zFolio is the source.
 */
final class Mirror {

	public const TYPE        = 'bristlecone_zfolio';
	public const BASE_OPTION = 'bristlecone_zfolio_mirror_base';
	public const MOVED       = 'bristlecone_zfolio_mirror_moved';
	public const FLUSH       = 'bristlecone_zfolio_flush_rewrite';
	public const HOOK        = 'bristlecone_zfolio_mirror';

	private const META_NODE = '_bristlecone_zfolio_node';
	private const META_CONN = '_bristlecone_zfolio_conn';
	private const META_HASH = '_bristlecone_zfolio_hash';

	/**
	 * Most post writes in one run; the rest follow in a scheduled run.
	 */
	public const BATCH = 100;

	/**
	 * Most remembered old addresses.
	 */
	private const MOVED_MAX = 1000;

	/**
	 * What each connection was last reconciled at, this request.
	 *
	 * @var array<string,string>
	 */
	private static array $done = array();

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_type' ) );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_action( 'bristlecone_zfolio_synced', array( self::class, 'reconcile' ) );
		add_action( 'bristlecone_zfolio_status_changed', array( self::class, 'reconcile' ) );
		add_action( self::HOOK, array( self::class, 'reconcile' ) );
		add_filter( 'the_content', array( self::class, 'content' ), PHP_INT_MAX );
		add_filter( 'map_meta_cap', array( self::class, 'read_only' ), 10, 4 );
		add_filter( 'bristlecone_zfolio_page_url', array( self::class, 'page_url' ), 5, 3 );
		add_action( 'template_redirect', array( self::class, 'redirect_moved' ) );
	}

	/**
	 * The address prefix, such as "docs".
	 */
	public static function base(): string {
		$base = sanitize_title( (string) get_option( self::BASE_OPTION, 'docs' ) );

		return '' !== $base ? $base : 'docs';
	}

	/**
	 * Register the post type.
	 */
	public static function register_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'zFolio Pages', 'bristlecone-zfolio' ),
					'singular_name' => __( 'zFolio Page', 'bristlecone-zfolio' ),
				),
				'public'              => true,
				'hierarchical'        => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => false,
				'exclude_from_search' => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'excerpt', 'page-attributes' ),
				'rewrite'             => array(
					'slug'       => self::base(),
					'with_front' => false,
				),
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Flush rewrite rules once after the prefix changes or mirroring starts.
	 */
	public static function maybe_flush(): void {
		if ( get_option( self::FLUSH ) ) {
			delete_option( self::FLUSH );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Ask for a rewrite flush on the next request.
	 */
	public static function request_flush(): void {
		update_option( self::FLUSH, 1, true );
	}

	/**
	 * Nobody edits or deletes mirrored posts in WordPress.
	 *
	 * @param array<int,string> $caps    Primitive caps.
	 * @param string            $cap     Meta cap.
	 * @param int               $user_id User.
	 * @param array<int,mixed>  $args    Arguments.
	 * @return array<int,string>
	 */
	public static function read_only( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) && isset( $args[0] ) && self::TYPE === get_post_type( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Replace a mirrored post's content with its stored page, after every
	 * other filter, so nothing (shortcodes, wpautop) runs over feed HTML.
	 *
	 * @param string $content Content.
	 */
	public static function content( $content ) {
		$post = get_post();

		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type ) {
			return $content;
		}

		[ $connection_id, $key ] = array_pad( explode( '|', (string) get_post_meta( $post->ID, self::META_NODE, true ), 2 ), 2, '' );
		$page                    = (string) get_post_meta( $post->ID, '_bristlecone_zfolio_page', true );

		if ( '' !== $page ) {
			return Render::page( $connection_id, $page, 'zfolio-mirror' );
		}

		return self::children_list( $post );
	}

	/**
	 * A folder's (or the root's) contents as a list of links.
	 *
	 * @param WP_Post $post Folder post.
	 */
	private static function children_list( WP_Post $post ): string {
		$children = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_parent'    => $post->ID,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One folder's children; feeds hold at most 2,000 pages.
				'no_found_rows'  => true,
			)
		);

		if ( ! $children ) {
			return '';
		}

		$items = '';

		foreach ( $children as $child ) {
			$excerpt = '' !== $child->post_excerpt ? '<br><span class="zfolio-mirror-excerpt">' . esc_html( $child->post_excerpt ) . '</span>' : '';
			$items  .= '<li><a href="' . esc_url( (string) get_permalink( $child ) ) . '">' . esc_html( get_the_title( $child ) ) . '</a>' . $excerpt . '</li>';
		}

		return '<ul class="zfolio-mirror-index">' . $items . '</ul>';
	}

	/**
	 * Links between pages of a mirrored connection point at the mirror.
	 *
	 * @param string|null $url           URL so far.
	 * @param string      $connection_id Connection id.
	 * @param string      $slug          Page slug.
	 * @return string|null
	 */
	public static function page_url( $url, string $connection_id, string $slug ) {
		$connection = Connections::get( $connection_id );

		if ( null === $connection || empty( $connection['mirror'] ) ) {
			return $url;
		}

		$page_id = Store::slugs( $connection_id )[ $slug ] ?? '';
		$post_id = self::targets( $connection_id )[ $page_id ] ?? 0;

		return $post_id > 0 ? (string) get_permalink( $post_id ) : $url;
	}

	/**
	 * Published mirrored posts by page id, read once per request.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,int>
	 */
	private static function targets( string $connection_id ): array {
		static $cache = array();

		if ( isset( $cache[ $connection_id ] ) ) {
			return $cache[ $connection_id ];
		}

		$out = array();

		foreach ( self::posts( $connection_id ) as $post ) {
			$page = (string) get_post_meta( $post->ID, '_bristlecone_zfolio_page', true );

			if ( '' !== $page && 'publish' === $post->post_status ) {
				$out[ $page ] = $post->ID;
			}
		}

		$cache[ $connection_id ] = $out;

		return $out;
	}

	/**
	 * Bring a connection's mirrored posts in line with its stored pages.
	 *
	 * @param string $id Connection id.
	 */
	public static function reconcile( $id ): void {
		$id         = (string) $id;
		$connection = Connections::get( $id );

		if ( null === $connection || empty( $connection['mirror'] ) ) {
			self::remove( $id );
			return;
		}

		$state = Connections::state( $id );
		$mark  = $state['status'] . '|' . $state['revision'] . '|' . (int) $state['pending'] . '|' . (int) $state['last_changed'] . '|' . md5( (string) wp_json_encode( $connection ) );

		// One pass per state per request: a sync can trigger both hooks.
		if ( ( self::$done[ $id ] ?? '' ) === $mark ) {
			return;
		}

		self::$done[ $id ] = $mark;

		$status = in_array( $state['status'], array( 'paused', 'revoked' ), true ) && 'hide' === $connection['takedown'] ? 'draft' : 'publish';

		if ( 'new' === $state['status'] || ! empty( $state['pending'] ) ) {
			return; // Wait for a complete first sync.
		}

		$rows = Store::index( $id );
		$tree = Tree::build( (string) $state['kind'], (string) $connection['mirror_slug'], (string) $state['title'], (array) $state['folders'], $rows );
		$hash = array();

		foreach ( $rows as $row ) {
			$hash[ (string) $row['page_id'] ] = (string) $row['hash'];
		}

		$existing = self::posts( $id );
		$writes   = 0;
		$changed  = array();
		$ids      = array();

		foreach ( $tree as $key => $node ) {
			$post      = $existing[ $key ] ?? null;
			$parent_id = '' === $node['parent'] ? 0 : (int) ( $ids[ $node['parent'] ] ?? 0 );

			if ( '' !== $node['parent'] && 0 === $parent_id ) {
				continue; // Its parent wasn't written yet; next run.
			}

			$page_hash = '' !== $node['page'] ? ( $hash[ $node['page'] ] ?? '' ) : '';
			$same      = null !== $post
				&& $post->post_title === $node['title']
				&& $post->post_name === $node['slug']
				&& (int) $post->post_parent === $parent_id
				&& (int) $post->menu_order === $node['order']
				&& $post->post_excerpt === $node['excerpt']
				&& $post->post_status === $status
				&& (string) get_post_meta( $post->ID, '_bristlecone_zfolio_page', true ) === $node['page']
				&& (string) get_post_meta( $post->ID, self::META_HASH, true ) === $page_hash;

			if ( $same ) {
				$ids[ $key ] = $post->ID;
				continue;
			}

			if ( $writes >= self::BATCH ) {
				wp_schedule_single_event( time() + 30, self::HOOK, array( $id ) );
				return;
			}

			$post_id = self::write( $id, $node, $parent_id, $status, $page_hash, $post );

			++$writes;

			if ( $post_id > 0 ) {
				$ids[ $key ] = $post_id;
				$changed[]   = $post_id;
			}
		}

		foreach ( $existing as $key => $post ) {
			if ( ! isset( $tree[ $key ] ) ) {
				Cache::purge_posts( array( $post->ID ) );
				wp_delete_post( $post->ID, true );
			}
		}

		if ( $changed ) {
			Cache::purge_posts( array_values( array_unique( $changed ) ) );
		}
	}

	/**
	 * Insert or update one mirrored post.
	 *
	 * @param string              $id        Connection id.
	 * @param array<string,mixed> $node      Tree node.
	 * @param int                 $parent_id Parent post.
	 * @param string              $status    Post status.
	 * @param string              $page_hash Stored page hash.
	 * @param WP_Post|null        $post      Existing post.
	 */
	private static function write( string $id, array $node, int $parent_id, string $status, string $page_hash, ?WP_Post $post ): int {
		if ( null !== $post && 'publish' === $post->post_status && ( $post->post_name !== $node['slug'] || (int) $post->post_parent !== $parent_id ) ) {
			self::remember_move( $post );
		}

		$text = '';

		if ( '' !== $node['page'] ) {
			$row  = Store::get( $id, (string) $node['page'] );
			$text = null !== $row ? trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $row['html'] ) ) ) : '';
		}

		$data = array(
			'post_type'      => self::TYPE,
			'post_title'     => (string) $node['title'],
			'post_name'      => (string) $node['slug'],
			'post_parent'    => $parent_id,
			'menu_order'     => (int) $node['order'],
			'post_excerpt'   => (string) $node['excerpt'],
			'post_status'    => $status,
			// Plain text for search and feeds; display comes from the store.
			'post_content'   => esc_html( $text ),
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'meta_input'     => array(
				self::META_NODE            => $id . '|' . $node['key'],
				self::META_CONN            => $id,
				'_bristlecone_zfolio_page' => (string) $node['page'],
				self::META_HASH            => $page_hash,
			),
		);

		if ( null !== $post ) {
			$data['ID'] = $post->ID;
		}

		$result = null !== $post ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );

		return is_wp_error( $result ) ? 0 : (int) $result;
	}

	/**
	 * Remember a post's current address before it moves.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function remember_move( WP_Post $post ): void {
		$path = trim( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ), '/' );

		if ( '' === $path ) {
			return;
		}

		$moved = (array) get_option( self::MOVED, array() );
		unset( $moved[ $path ] );
		$moved[ $path ] = $post->ID;

		if ( count( $moved ) > self::MOVED_MAX ) {
			$moved = array_slice( $moved, -self::MOVED_MAX, null, true );
		}

		update_option( self::MOVED, $moved, false );
	}

	/**
	 * Send a visitor at an old address to where the page is now.
	 */
	public static function redirect_moved(): void {
		if ( ! is_404() ) {
			return;
		}

		$path  = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared, never output.
		$moved = (array) get_option( self::MOVED, array() );
		$home  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
		}

		$path = rawurldecode( $path );

		if ( ! isset( $moved[ $path ] ) ) {
			return;
		}

		$post = get_post( (int) $moved[ $path ] );

		if ( $post instanceof WP_Post && self::TYPE === $post->post_type && 'publish' === $post->post_status ) {
			wp_safe_redirect( (string) get_permalink( $post ), 301 );
			exit;
		}
	}

	/**
	 * A connection's mirrored posts, keyed by node key.
	 *
	 * @param string $id Connection id.
	 * @return array<string,WP_Post>
	 */
	private static function posts( string $id ): array {
		$posts = get_posts(
			array(
				'post_type'        => self::TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_CONN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$out = array();

		foreach ( $posts as $post ) {
			$node = (string) get_post_meta( $post->ID, self::META_NODE, true );
			$key  = substr( $node, strlen( $id ) + 1 );

			if ( isset( $out[ $key ] ) ) {
				wp_delete_post( $post->ID, true ); // A duplicate from an interrupted run.
				continue;
			}

			$out[ $key ] = $post;
		}

		return $out;
	}

	/**
	 * The mirrored post for a node.
	 *
	 * @param string $id  Connection id.
	 * @param string $key Node key or page id.
	 */
	public static function find( string $id, string $key ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'        => self::TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_NODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $id . '|' . $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * The root post's address, for the settings screen.
	 *
	 * @param string $id Connection id.
	 */
	public static function root_url( string $id ): string {
		$post = self::find( $id, Tree::ROOT );

		return null !== $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
	}

	/**
	 * Delete a connection's mirrored posts.
	 *
	 * @param string $id Connection id.
	 */
	public static function remove( string $id ): void {
		$posts = self::posts( $id );

		if ( ! $posts ) {
			return;
		}

		Cache::purge_posts( array_map( static fn ( WP_Post $post ): int => $post->ID, array_values( $posts ) ) );

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
}
