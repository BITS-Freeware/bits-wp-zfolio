<?php
/**
 * Shape checks for manifest and page JSON.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Feed;

/**
 * Checks only what the plugin relies on, and ignores unknown fields, as the
 * contract asks. Returns a normalized copy, or null when the data is unusable.
 */
final class Validator {

	public const PAGE_ID   = '/^pg_[a-z2-7]{26}$/';
	public const FOLDER_ID = '/^fl_[a-z2-7]{26}$/';
	public const FEED_ID   = '/^zfd_[A-Za-z0-9_-]{22}$/';
	public const SLUG      = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
	public const HASH      = '/^[0-9a-f]{64}$/';

	/**
	 * Validate a manifest.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 * @return array<string,mixed>|null
	 */
	public static function manifest( array $data ): ?array {
		if ( 1 !== ( $data['apiVersion'] ?? null ) ) {
			return null;
		}

		$feed_id  = self::str( $data['feedId'] ?? null );
		$revision = $data['revision'] ?? null;

		if ( ! preg_match( self::FEED_ID, $feed_id ) || ! is_int( $revision ) || $revision < 0 ) {
			return null;
		}

		if ( ! is_array( $data['pages'] ?? null ) || ! array_is_list( $data['pages'] ) ) {
			return null;
		}

		$pages = array();
		$slugs = array();

		foreach ( $data['pages'] as $index => $page ) {
			if ( ! is_array( $page ) ) {
				return null;
			}

			$id   = self::str( $page['id'] ?? null );
			$slug = self::str( $page['slug'] ?? null );
			$hash = self::str( $page['hash'] ?? null );

			if ( ! preg_match( self::PAGE_ID, $id ) || ! self::valid_slug( $slug ) || ! preg_match( self::HASH, $hash ) ) {
				return null;
			}

			if ( isset( $pages[ $id ] ) || isset( $slugs[ $slug ] ) ) {
				return null;
			}

			$slugs[ $slug ] = true;
			$pages[ $id ]   = array(
				'id'          => $id,
				'slug'        => $slug,
				'title'       => self::str( $page['title'] ?? null ),
				'description' => self::str( $page['description'] ?? null ),
				'parents'     => self::ids( $page['parents'] ?? array(), self::FOLDER_ID ),
				'order'       => is_int( $page['order'] ?? null ) ? $page['order'] : (int) $index,
				'updatedAt'   => self::str( $page['updatedAt'] ?? null ),
				'hash'        => $hash,
			);
		}

		$folders = array();

		foreach ( is_array( $data['folders'] ?? null ) ? $data['folders'] : array() as $folder ) {
			$id = is_array( $folder ) ? self::str( $folder['id'] ?? null ) : '';

			if ( preg_match( self::FOLDER_ID, $id ) ) {
				$folders[ $id ] = array(
					'id'      => $id,
					'title'   => self::str( $folder['title'] ?? null ),
					'parents' => self::ids( $folder['parents'] ?? array(), self::FOLDER_ID ),
					'order'   => is_int( $folder['order'] ?? null ) ? $folder['order'] : 0,
				);
			}
		}

		return array(
			'feedId'           => $feed_id,
			'kind'             => 'document' === ( $data['kind'] ?? '' ) ? 'document' : 'folder',
			'title'            => self::str( $data['title'] ?? null ),
			'revision'         => $revision,
			'allowlistVersion' => is_int( $data['allowlistVersion'] ?? null ) ? $data['allowlistVersion'] : 1,
			'folders'          => $folders,
			'pages'            => $pages,
		);
	}

	/**
	 * Validate a page.
	 *
	 * @param array<mixed> $data    Decoded JSON.
	 * @param string       $feed_id Feed id the page must belong to.
	 * @return array<string,mixed>|null
	 */
	public static function page( array $data, string $feed_id ): ?array {
		if ( 1 !== ( $data['apiVersion'] ?? null ) || $feed_id !== ( $data['feedId'] ?? null ) ) {
			return null;
		}

		$id   = self::str( $data['id'] ?? null );
		$hash = self::str( $data['hash'] ?? null );

		if ( ! preg_match( self::PAGE_ID, $id ) || ! preg_match( self::HASH, $hash ) || ! is_string( $data['html'] ?? null ) ) {
			return null;
		}

		$headings = array();

		foreach ( is_array( $data['headings'] ?? null ) ? array_slice( $data['headings'], 0, 1000 ) : array() as $heading ) {
			if ( is_array( $heading ) && is_int( $heading['level'] ?? null ) && is_string( $heading['id'] ?? null ) ) {
				$headings[] = array(
					'level' => max( 1, min( 6, $heading['level'] ) ),
					'text'  => self::str( $heading['text'] ?? null ),
					'id'    => $heading['id'],
				);
			}
		}

		$lang = self::str( $data['lang'] ?? null );

		return array(
			'id'          => $id,
			'html'        => $data['html'],
			'headings'    => $headings,
			'hash'        => $hash,
			'lang'        => preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang ) ? $lang : '',
			'publishedAt' => self::str( $data['publishedAt'] ?? null ),
			'updatedAt'   => self::str( $data['updatedAt'] ?? null ),
		);
	}

	/**
	 * Whether a slug keeps to the contract.
	 *
	 * @param string $slug Slug.
	 */
	public static function valid_slug( string $slug ): bool {
		return strlen( $slug ) <= 200 && 1 === preg_match( self::SLUG, $slug );
	}

	/**
	 * A string field, or ''.
	 *
	 * @param mixed $value Value.
	 */
	private static function str( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * A list of ids matching a pattern; others dropped.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pattern Regex.
	 * @return list<string>
	 */
	private static function ids( mixed $value, string $pattern ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( $value, static fn ( $id ) => is_string( $id ) && preg_match( $pattern, $id ) ) );
	}
}
