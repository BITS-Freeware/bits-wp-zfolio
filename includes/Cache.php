<?php
/**
 * Clears caches after content changes.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * Clears the object cache for affected posts and asks page-cache plugins to
 * purge their URLs. A CDN can follow with the bristlecone_zfolio_purged action,
 * which receives every affected URL.
 */
final class Cache {

	/**
	 * Purge posts that show some pages of a connection.
	 *
	 * @param string       $connection_id Connection id.
	 * @param list<string> $page_ids      Changed or removed page ids.
	 * @param bool         $all           Purge every post of the connection.
	 */
	public static function purge_pages( string $connection_id, array $page_ids, bool $all = false ): void {
		if ( $all ) {
			self::purge_connection( $connection_id );
			return;
		}

		$posts = array();

		foreach ( $page_ids as $page_id ) {
			$posts = array_merge( $posts, Refs::posts_for( $connection_id, $page_id ) );
		}

		self::purge_posts( array_values( array_unique( $posts ) ) );
	}

	/**
	 * Purge every post that shows a connection's pages.
	 *
	 * @param string $connection_id Connection id.
	 */
	public static function purge_connection( string $connection_id ): void {
		self::purge_posts( Refs::posts_for_connection( $connection_id ) );
	}

	/**
	 * Purge posts.
	 *
	 * @param list<int> $post_ids Post ids.
	 */
	public static function purge_posts( array $post_ids ): void {
		$urls = array();

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );

			$url = get_permalink( $post_id );

			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}

			self::purge_page_caches( $post_id, is_string( $url ) ? $url : '' );
		}

		if ( $post_ids ) {
			/**
			 * Fires after the plugin cleared caches for changed zFolio content.
			 *
			 * @param list<string> $urls     Affected URLs.
			 * @param list<int>    $post_ids Affected post ids.
			 */
			do_action( 'bristlecone_zfolio_purged', $urls, $post_ids );
		}
	}

	/**
	 * Ask common page-cache plugins to drop one post, through their public APIs.
	 *
	 * @param int    $post_id Post id.
	 * @param string $url     Its URL.
	 */
	private static function purge_page_caches( int $post_id, string $url ): void {
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}

		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}

		if ( '' !== $url && function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}

		do_action( 'litespeed_purge_post', $post_id );
	}
}
