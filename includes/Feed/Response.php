<?php
/**
 * One answer from the feed, reduced to what the plugin acts on.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Feed;

/**
 * Immutable value object. Build it from raw parts with from_parts(), which
 * holds all of the contract's parsing rules and needs no WordPress.
 */
final class Response {

	public const OK           = 'ok';
	public const NOT_MODIFIED = 'not_modified';
	public const NOT_FOUND    = 'not_found';
	public const PAUSED       = 'paused';
	public const REVOKED      = 'revoked';
	public const GONE         = 'gone';
	public const RATE_LIMITED = 'rate_limited';
	public const FAILED       = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string            $outcome      One of the constants above.
	 * @param int               $status       HTTP status, 0 for a network error.
	 * @param array<mixed>|null $data         Decoded JSON body for OK.
	 * @param string            $etag         ETag as sent, quotes included.
	 * @param int               $retry_after  Seconds to wait, for RATE_LIMITED.
	 * @param string            $deprecation  Raw Deprecation header.
	 * @param string            $sunset       Raw Sunset header.
	 * @param string            $message      Short reason, for admins.
	 * @param int               $current      currentVersion from a 410 body.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly int $status = 0,
		public readonly ?array $data = null,
		public readonly string $etag = '',
		public readonly int $retry_after = 0,
		public readonly string $deprecation = '',
		public readonly string $sunset = '',
		public readonly string $message = '',
		public readonly int $current = 0,
	) {}

	/**
	 * Interpret a raw HTTP answer.
	 *
	 * @param int                  $status  HTTP status (0 when the request failed).
	 * @param array<string,string> $headers Lower-cased header names.
	 * @param string               $body    Raw body.
	 * @param string               $error   Transport error, when status is 0.
	 */
	public static function from_parts( int $status, array $headers, string $body, string $error = '' ): self {
		$headers     = array_change_key_case( $headers, CASE_LOWER );
		$etag        = trim( (string) ( $headers['etag'] ?? '' ) );
		$deprecation = trim( (string) ( $headers['deprecation'] ?? '' ) );
		$sunset      = trim( (string) ( $headers['sunset'] ?? '' ) );

		if ( 0 === $status ) {
			return new self( self::FAILED, 0, null, '', 0, '', '', '' !== $error ? $error : 'network error' );
		}

		if ( 304 === $status ) {
			return new self( self::NOT_MODIFIED, 304, null, $etag, 0, $deprecation, $sunset );
		}

		if ( 429 === $status ) {
			return new self( self::RATE_LIMITED, 429, null, '', self::retry_after( (string) ( $headers['retry-after'] ?? '' ) ), $deprecation, $sunset, 'rate limited' );
		}

		$json = json_decode( $body, true );
		$json = is_array( $json ) ? $json : null;

		if ( 200 === $status ) {
			if ( null === $json ) {
				return new self( self::FAILED, 200, null, '', 0, $deprecation, $sunset, 'invalid JSON' );
			}

			return new self( self::OK, 200, $json, $etag, 0, $deprecation, $sunset );
		}

		if ( 404 === $status ) {
			$code = is_array( $json ) && is_string( $json['code'] ?? null ) ? $json['code'] : 'not_found';

			return match ( $code ) {
				'paused'  => new self( self::PAUSED, 404, null, '', 0, $deprecation, $sunset, 'paused' ),
				'revoked' => new self( self::REVOKED, 404, null, '', 0, $deprecation, $sunset, 'revoked' ),
				default   => new self( self::NOT_FOUND, 404, null, '', 0, $deprecation, $sunset, 'not found' ),
			};
		}

		if ( 410 === $status ) {
			$current = is_array( $json ) && is_int( $json['currentVersion'] ?? null ) ? $json['currentVersion'] : 0;

			return new self( self::GONE, 410, null, '', 0, $deprecation, $sunset, 'version retired', $current );
		}

		return new self( self::FAILED, $status, null, '', 0, $deprecation, $sunset, 'HTTP ' . $status );
	}

	/**
	 * Seconds from a Retry-After header (delta seconds or an HTTP date).
	 * Clamped to between 30 seconds and one hour.
	 *
	 * @param string $value Header value.
	 */
	public static function retry_after( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 60;
		}

		if ( ctype_digit( $value ) ) {
			$seconds = (int) $value;
		} else {
			$when    = strtotime( $value );
			$seconds = false === $when ? 60 : $when - time();
		}

		return max( 30, min( 3600, $seconds ) );
	}

	/**
	 * The ETag to send back, or '' when there is none.
	 */
	public function etag_for_request(): string {
		return $this->etag;
	}
}
