<?php
/**
 * Webhook signatures: `zFolio-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]`.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Webhook;

/**
 * Each `v1` is lowercase hex HMAC-SHA256, keyed with the secret's UTF-8
 * bytes, over `t + "." + raw body`. While a secret is rolled, zFolio sends a
 * second `v1` signed with the previous secret, so one stored secret (old or
 * new) is enough: the event is accepted when any `v1` matches.
 */
final class Signature {

	public const OK        = 'ok';
	public const MALFORMED = 'malformed';
	public const EXPIRED   = 'expired';
	public const MISMATCH  = 'mismatch';

	/**
	 * How far `t` may be from the site's clock, in seconds.
	 */
	public const TOLERANCE = 300;

	/**
	 * The shape of a webhook secret.
	 */
	public const SECRET = '/^zfwh_[A-Za-z0-9_-]{43}$/';

	/**
	 * Check a signature header against a body.
	 *
	 * @param string $header The zFolio-Signature header.
	 * @param string $body   The raw request body.
	 * @param string $secret The endpoint's secret.
	 * @param int    $now    Current Unix time.
	 * @return string One of the class constants.
	 */
	public static function verify( string $header, string $body, string $secret, int $now ): string {
		$t          = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] && 1 === preg_match( '/^[0-9]{1,12}$/', $pair[1] ) ) {
				$t = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && 1 === preg_match( '/^[0-9a-f]{64}$/', $pair[1] ) ) {
				$signatures[] = $pair[1];
			}
		}

		if ( null === $t || ! $signatures || '' === $secret || count( $signatures ) > 4 ) {
			return self::MALFORMED;
		}

		if ( abs( $now - $t ) > self::TOLERANCE ) {
			return self::EXPIRED;
		}

		$expected = self::sign( $secret, $t, $body );
		$match    = false;

		// Compare every candidate so timing doesn't reveal which one matched.
		foreach ( $signatures as $signature ) {
			$match = hash_equals( $expected, $signature ) || $match;
		}

		return $match ? self::OK : self::MISMATCH;
	}

	/**
	 * The signature zFolio would send.
	 *
	 * @param string $secret Secret.
	 * @param int    $t      Timestamp.
	 * @param string $body   Raw body.
	 */
	public static function sign( string $secret, int $t, string $body ): string {
		return hash_hmac( 'sha256', $t . '.' . $body, $secret );
	}

	/**
	 * Whether a string looks like a webhook secret.
	 *
	 * @param string $secret Candidate.
	 */
	public static function valid_secret( string $secret ): bool {
		return 1 === preg_match( self::SECRET, $secret );
	}

	/**
	 * A secret safe to show: its prefix and last four characters.
	 *
	 * @param string $secret Secret.
	 */
	public static function mask( string $secret ): string {
		return '' === $secret ? '' : 'zfwh_••••••••' . substr( $secret, -4 );
	}
}
