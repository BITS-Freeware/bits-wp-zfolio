<?php
/**
 * HTTP client for the zFolio feed.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Feed;

/**
 * Reads the feed from the WordPress server with wp_safe_remote_get().
 */
class Client {

	/**
	 * Fetch the manifest.
	 *
	 * @param string $address Normalized feed address.
	 * @param string $etag    ETag from the last complete sync, or ''.
	 */
	public function manifest( string $address, string $etag = '' ): Response {
		return $this->get( $address, $etag );
	}

	/**
	 * Fetch one page by id.
	 *
	 * @param string $address Normalized feed address.
	 * @param string $page_id Page id.
	 */
	public function page( string $address, string $page_id ): Response {
		return $this->get( Address::page( $address, $page_id ), '' );
	}

	/**
	 * The User-Agent the contract asks for.
	 */
	public static function user_agent(): string {
		return 'bristlecone-zfolio/' . BRISTLECONE_ZFOLIO_VERSION . ' WordPress/' . get_bloginfo( 'version' );
	}

	/**
	 * One GET.
	 *
	 * @param string $url  Address.
	 * @param string $etag ETag to send in If-None-Match.
	 */
	protected function get( string $url, string $etag ): Response {
		$headers = array( 'Accept' => 'application/json' );

		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$result = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'user-agent'  => self::user_agent(),
				'headers'     => $headers,
				// Manifests are capped at 4 MB, pages share a 50 MB feed cap.
				'limit_response_size' => 8 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $result ) ) {
			// The error text can echo the URL, and the URL is a credential.
			return Response::from_parts( 0, array(), '', 'network error (' . $result->get_error_code() . ')' );
		}

		$raw_headers = wp_remote_retrieve_headers( $result );
		$headers_out = array();

		foreach ( array( 'etag', 'retry-after', 'deprecation', 'sunset' ) as $name ) {
			$value = $raw_headers[ $name ] ?? '';

			if ( is_array( $value ) ) {
				$value = (string) reset( $value );
			}

			$headers_out[ $name ] = (string) $value;
		}

		return Response::from_parts(
			(int) wp_remote_retrieve_response_code( $result ),
			$headers_out,
			(string) wp_remote_retrieve_body( $result )
		);
	}
}
