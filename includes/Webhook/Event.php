<?php
/**
 * Webhook event bodies.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Webhook;

use Bristlecone\Zfolio\Feed\Validator;

/**
 * Validates an event and keeps only the fields the plugin acts on. Unknown
 * fields are ignored, and unknown types pass through so they can be
 * acknowledged and skipped, as the contract asks.
 */
final class Event {

	public const ID = '/^evt_[A-Za-z0-9_-]{22}$/';

	/**
	 * Most bytes a webhook body may have.
	 */
	public const MAX_BYTES = 65536;

	/**
	 * Parse a raw body.
	 *
	 * @param string $body Raw JSON.
	 * @return array{id:string,type:string,created:int,feedId:string,revision:?int,pages:array<int,string>,truncated:bool,what:string,until:int}|null
	 */
	public static function parse( string $body ): ?array {
		if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return null;
		}

		$data = json_decode( $body, true, 16 );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$id      = $data['id'] ?? null;
		$type    = $data['type'] ?? null;
		$feed_id = $data['feedId'] ?? null;
		$fields  = $data['data'] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( self::ID, $id )
			|| ! is_string( $type ) || 1 !== preg_match( '/^[a-z]+(?:\.[a-z_]+)+$/', $type )
			|| ! is_string( $feed_id ) || 1 !== preg_match( Validator::FEED_ID, $feed_id )
			|| 1 !== ( $data['apiVersion'] ?? null )
			|| ! is_array( $fields ) ) {
			return null;
		}

		$created = is_string( $data['createdAt'] ?? null ) ? strtotime( $data['createdAt'] ) : false;

		if ( false === $created ) {
			return null;
		}

		$revision = $fields['revision'] ?? null;

		if ( null !== $revision && ! is_int( $revision ) ) {
			return null;
		}

		if ( in_array( $type, array( 'feed.updated', 'feed.page_removed' ), true ) && null === $revision ) {
			return null;
		}

		$pages = array();

		foreach ( array( 'changed', 'removed', 'pages' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $fields[ $key ] ) ) {
				return null;
			}

			foreach ( $fields[ $key ] as $page_id ) {
				if ( ! is_string( $page_id ) || 1 !== preg_match( Validator::PAGE_ID, $page_id ) ) {
					return null;
				}
			}
		}

		if ( 'feed.page_removed' === $type ) {
			if ( ! isset( $fields['pages'] ) ) {
				return null;
			}

			$pages = array_values( array_unique( $fields['pages'] ) );
		} elseif ( 'feed.updated' === $type ) {
			$pages = array_values( array_unique( $fields['removed'] ?? array() ) );
		}

		$what  = '';
		$until = 0;

		if ( 'feed.rotated' === $type ) {
			$what = $fields['what'] ?? '';

			if ( ! in_array( $what, array( 'address', 'secret' ), true ) ) {
				return null;
			}

			$raw   = 'address' === $what ? ( $fields['graceUntil'] ?? '' ) : ( $fields['previousUntil'] ?? '' );
			$until = is_string( $raw ) && '' !== $raw ? (int) strtotime( $raw ) : 0;
		}

		return array(
			'id'        => $id,
			'type'      => $type,
			'created'   => (int) $created,
			'feedId'    => $feed_id,
			'revision'  => $revision,
			'pages'     => $pages,
			'truncated' => true === ( $fields['truncated'] ?? false ),
			'what'      => $what,
			'until'     => max( 0, $until ),
		);
	}
}
