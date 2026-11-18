<?php
/**
 * A feed address: validation and masking.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Feed;

/**
 * Feed addresses are credentials. They are validated on save and only ever
 * shown masked.
 */
final class Address {

	/**
	 * Path shape for a v1 feed: /feed/v1/<token>, where the token is opaque.
	 */
	private const PATH_PATTERN = '#^/feed/v1/([A-Za-z0-9_-]{16,128})/?$#';

	/**
	 * The feed host zFolio serves feeds from.
	 */
	public const HOST = 'zfolio.zuuid.cc';

	/**
	 * Hosts a feed address may use: zFolio's, unless a site deliberately
	 * allows another (a test server, say) with the filter.
	 *
	 * @return list<string>
	 */
	public static function hosts(): array {
		/**
		 * Filters the hosts a feed address may point at.
		 *
		 * @param list<string> $hosts Lowercase host names.
		 */
		$hosts = apply_filters( 'bristlecone_zfolio_feed_hosts', array( self::HOST ) );

		return array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) $hosts ) ) ) );
	}

	/**
	 * Normalize a pasted feed address, or return null when it is not one.
	 *
	 * Only zFolio's host is accepted (see hosts()), and the request still goes
	 * through wp_safe_remote_get() with redirects off.
	 *
	 * @param string $raw Pasted address.
	 */
	public static function normalize( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw || strlen( $raw ) > 512 || preg_match( '/\s/', $raw ) ) {
			return null;
		}

		$parts = wp_parse_url( $raw );

		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if ( 'https' !== $scheme || '' === $host ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) || ! str_contains( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( ! in_array( $host, self::hosts(), true ) ) {
			return null;
		}

		if ( ! preg_match( self::PATH_PATTERN, $path, $match ) ) {
			return null;
		}

		return 'https://' . $host . '/feed/v1/' . $match[1];
	}

	/**
	 * The address with its token hidden except the last four characters.
	 *
	 * @param string $address Normalized address.
	 */
	public static function mask( string $address ): string {
		if ( ! preg_match( '#^(https://[^/]+/feed/v1/)([A-Za-z0-9_-]+)$#', $address, $match ) ) {
			return '';
		}

		$token  = $match[2];
		$prefix = str_starts_with( $token, 'zff_' ) ? 'zff_' : '';
		$tail   = substr( $token, -4 );

		return $match[1] . $prefix . str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * Address of one page, by id or slug.
	 *
	 * @param string $address Normalized feed address.
	 * @param string $page    Page id or slug.
	 */
	public static function page( string $address, string $page ): string {
		return $address . '/pages/' . rawurlencode( $page );
	}
}
