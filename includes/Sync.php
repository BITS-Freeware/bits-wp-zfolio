<?php
/**
 * Brings a connection's stored pages in line with its feed.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

use Bristlecone\Zfolio\Content\Sanitizer;
use Bristlecone\Zfolio\Feed\Client;
use Bristlecone\Zfolio\Feed\Response;
use Bristlecone\Zfolio\Feed\Validator;

/**
 * One sync: fetch the manifest (conditionally), fetch pages whose hash
 * changed, delete pages the manifest no longer lists, and purge caches.
 *
 * Large first syncs are spread over several runs: each run fetches a batch
 * and schedules the next, and the manifest ETag is saved only once every page
 * is in, so a 304 can never hide unfinished work.
 */
final class Sync {

	/**
	 * Pages fetched per run.
	 */
	public const BATCH = 20;

	/**
	 * Constructor.
	 *
	 * @param Client $client Feed client.
	 */
	public function __construct( private readonly Client $client = new Client() ) {}

	/**
	 * Sync one connection.
	 *
	 * @param string $id    Connection id.
	 * @param bool   $force Ignore the stored ETag (Refresh now).
	 * @return array{ok:bool,message:string}
	 */
	public function run( string $id, bool $force = false ): array {
		$connection = Connections::get( $id );

		if ( null === $connection || '' === $connection['address'] ) {
			return array( 'ok' => false, 'message' => __( 'This connection has no feed address.', 'bristlecone-zfolio' ) );
		}

		$state = Connections::state( $id );

		if ( (int) $state['retry_until'] > time() ) {
			return array( 'ok' => false, 'message' => __( 'zFolio asked the site to wait before the next request. Try again in a minute.', 'bristlecone-zfolio' ) );
		}

		if ( ! self::lock( $id ) ) {
			return array( 'ok' => false, 'message' => __( 'An update is already running for this connection.', 'bristlecone-zfolio' ) );
		}

		try {
			return $this->sync( $id, $connection, $state, $force );
		} finally {
			self::unlock( $id );
		}
	}

	/**
	 * The sync itself, under the lock.
	 *
	 * @param string              $id         Connection id.
	 * @param array<string,mixed> $connection Settings.
	 * @param array<string,mixed> $state      State.
	 * @param bool                $force      Ignore the ETag.
	 * @return array{ok:bool,message:string}
	 */
	private function sync( string $id, array $connection, array $state, bool $force ): array {
		$etag     = ( $force || $state['pending'] ) ? '' : (string) $state['etag'];
		$response = $this->client->manifest( (string) $connection['address'], $etag );
		$now      = time();
		$common   = array(
			'last_checked' => $now,
			'deprecation'  => $response->deprecation,
			'sunset'       => $response->sunset,
		);

		switch ( $response->outcome ) {
			case Response::NOT_MODIFIED:
				Connections::update_state( $id, $common + array( 'last_error' => '' ) + self::active_status( $state ) );
				return array( 'ok' => true, 'message' => __( 'No changes since the last update.', 'bristlecone-zfolio' ) );

			case Response::RATE_LIMITED:
				Connections::update_state( $id, $common + array( 'retry_until' => $now + $response->retry_after, 'last_error' => 'rate limited' ) );
				Cron::schedule( $id, $response->retry_after );
				return array( 'ok' => false, 'message' => __( 'zFolio asked the site to slow down. The next update will wait.', 'bristlecone-zfolio' ) );

			case Response::PAUSED:
			case Response::REVOKED:
				$status = Response::PAUSED === $response->outcome ? 'paused' : 'revoked';

				if ( $status !== $state['status'] ) {
					Cache::purge_connection( $id );
				}

				Connections::update_state( $id, $common + array( 'status' => $status, 'last_error' => '' ) );
				return array(
					'ok'      => false,
					'message' => 'paused' === $status
						? __( 'The feed is paused by its owner in zFolio.', 'bristlecone-zfolio' )
						: __( 'The feed was turned off in zFolio.', 'bristlecone-zfolio' ),
				);

			case Response::GONE:
				Connections::update_state( $id, $common + array( 'gone_current' => $response->current, 'last_error' => 'version retired' ) );
				return array( 'ok' => false, 'message' => __( 'zFolio no longer serves this version of the feed. Update the plugin.', 'bristlecone-zfolio' ) );

			case Response::NOT_FOUND:
				// Never take content down on a polling 404 alone.
				Connections::update_state( $id, $common + array( 'last_error' => 'not found' ) );
				return array( 'ok' => false, 'message' => __( 'zFolio doesn’t recognize this feed address. If the owner rotated it, paste the new address.', 'bristlecone-zfolio' ) );

			case Response::OK:
				break;

			default:
				Connections::update_state( $id, $common + array( 'last_error' => $response->message ) );
				return array( 'ok' => false, 'message' => __( 'zFolio couldn’t be reached. The site keeps showing what it has.', 'bristlecone-zfolio' ) );
		}

		$manifest = Validator::manifest( (array) $response->data );

		if ( null === $manifest ) {
			Connections::update_state( $id, $common + array( 'last_error' => 'invalid manifest' ) );
			return array( 'ok' => false, 'message' => __( 'zFolio sent a feed the plugin can’t read. The site keeps showing what it has.', 'bristlecone-zfolio' ) );
		}

		if ( '' !== $state['feed_id'] && $manifest['feedId'] !== $state['feed_id'] ) {
			Connections::update_state( $id, $common + array( 'last_error' => 'different feed' ) );
			return array( 'ok' => false, 'message' => __( 'This address now points at a different feed. Clear the connection’s content before using it.', 'bristlecone-zfolio' ) );
		}

		if ( Plan::is_stale( $manifest['revision'], (int) $state['revision'] ) ) {
			Connections::update_state( $id, $common + array( 'last_error' => '' ) + self::active_status( $state ) );
			return array( 'ok' => true, 'message' => __( 'No changes since the last update.', 'bristlecone-zfolio' ) );
		}

		$plan = Plan::make( $manifest['pages'], Store::hashes( $id ) );

		foreach ( $manifest['pages'] as $page ) {
			Store::upsert_meta( $id, $page );
		}

		$deleted = Store::delete_except( $id, array_keys( $manifest['pages'] ) );
		$fetched = array();
		$failed  = 0;

		foreach ( array_slice( $plan->fetch, 0, self::BATCH ) as $page_id ) {
			$page = $this->client->page( (string) $connection['address'], $page_id );

			if ( Response::RATE_LIMITED === $page->outcome ) {
				Connections::update_state( $id, array( 'retry_until' => $now + $page->retry_after ) );
				++$failed;
				break;
			}

			$valid = Response::OK === $page->outcome ? Validator::page( (array) $page->data, $manifest['feedId'] ) : null;

			if ( null === $valid || $valid['id'] !== $page_id ) {
				++$failed;
				continue;
			}

			$valid['html'] = Sanitizer::clean( $valid['html'] );
			Store::save_content( $id, $valid, min( (int) $manifest['allowlistVersion'], Sanitizer::ALLOWLIST_VERSION ) );
			$fetched[] = $page_id;
		}

		$remaining = count( $plan->fetch ) - count( $fetched );
		$complete  = 0 === $remaining;

		Connections::update_state(
			$id,
			$common + array(
				'status'       => 'active',
				'feed_id'      => $manifest['feedId'],
				'title'        => $manifest['title'],
				'kind'         => $manifest['kind'],
				'revision'     => $complete ? $manifest['revision'] : (int) $state['revision'],
				'etag'         => $complete ? $response->etag : '',
				'folders'      => $manifest['folders'],
				'pending'      => ! $complete,
				'last_changed' => ( $fetched || $deleted ) ? $now : (int) $state['last_changed'],
				'last_error'   => $failed > 0 ? 'some pages could not be fetched' : '',
			)
		);

		if ( $fetched || $deleted || 'active' !== $state['status'] ) {
			Cache::purge_pages( $id, array_merge( $fetched, $deleted ), 'active' !== $state['status'] );
		}

		if ( ! $complete ) {
			Cron::schedule( $id, $failed > 0 ? 300 : 30 );
		}

		/* translators: 1: pages updated, 2: pages removed. */
		$message = sprintf( __( 'Updated %1$d pages and removed %2$d.', 'bristlecone-zfolio' ), count( $fetched ), count( $deleted ) );

		if ( ! $complete ) {
			/* translators: %d: pages still to fetch. */
			$message .= ' ' . sprintf( __( '%d more will follow shortly.', 'bristlecone-zfolio' ), $remaining );
		}

		return array( 'ok' => true, 'message' => $message );
	}

	/**
	 * A 304 or 200 means the feed serves again.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,string>
	 */
	private static function active_status( array $state ): array {
		if ( in_array( $state['status'], array( 'paused', 'revoked' ), true ) ) {
			return array( 'status' => 'active' );
		}

		return array();
	}

	/**
	 * Take the per-connection lock. Stale locks expire after five minutes.
	 *
	 * @param string $id Connection id.
	 */
	private static function lock( string $id ): bool {
		$name = 'bristlecone_zfolio_lock_' . $id;

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		if ( (int) get_option( $name ) < time() - 300 ) {
			update_option( $name, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param string $id Connection id.
	 */
	private static function unlock( string $id ): void {
		delete_option( 'bristlecone_zfolio_lock_' . $id );
	}
}
