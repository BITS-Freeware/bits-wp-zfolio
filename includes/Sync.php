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
	 * Most pages fetched per run.
	 */
	public const BATCH = 200;

	/**
	 * Seconds of page fetching per run before the rest waits.
	 */
	public const BUDGET = 20;

	/**
	 * Seconds before an unfinished sync continues.
	 */
	public const CONTINUE_AFTER = 300;

	/**
	 * Tries per page and hash before the page waits for the next revision.
	 */
	public const ATTEMPTS = 3;

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
	 * @return array{ok:bool,message:string,busy?:bool,retry?:bool} busy: another sync holds the lock; retry: a failure worth retrying soon.
	 */
	public function run( string $id, bool $force = false ): array {
		$connection = Connections::get( $id );

		if ( null === $connection || '' === $connection['address'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'This connection has no feed address.', 'bristlecone-zfolio' ),
			);
		}

		$state = Connections::state( $id );

		if ( (int) $state['retry_until'] > time() ) {
			return array(
				'ok'      => false,
				'message' => __( 'zFolio asked the site to wait before the next request. Try again in a minute.', 'bristlecone-zfolio' ),
			);
		}

		if ( ! self::lock( $id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'An update is already running for this connection.', 'bristlecone-zfolio' ),
				'busy'    => true,
			);
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
	 * @return array{ok:bool,message:string,retry?:bool}
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
				Connections::update_state( $id, $common + array( 'last_error' => '' ) + self::reactivate( $id, $state ) );
				return array(
					'ok'      => true,
					'message' => __( 'No changes since the last update.', 'bristlecone-zfolio' ),
				);

			case Response::RATE_LIMITED:
				Connections::update_state(
					$id,
					$common + array(
						'retry_until' => $now + $response->retry_after,
						'last_error'  => 'rate limited',
					)
				);
				Cron::schedule( $id, $response->retry_after );
				return array(
					'ok'      => false,
					'message' => __( 'zFolio asked the site to slow down. The next update will wait.', 'bristlecone-zfolio' ),
				);

			case Response::PAUSED:
			case Response::REVOKED:
				$status = Response::PAUSED === $response->outcome ? 'paused' : 'revoked';

				if ( $status !== $state['status'] ) {
					Cache::purge_connection( $id );
				}

				Connections::update_state(
					$id,
					$common + array(
						'status'     => $status,
						'last_error' => '',
					)
				);
				return array(
					'ok'      => false,
					'message' => 'paused' === $status
						? __( 'The feed is paused by its owner in zFolio.', 'bristlecone-zfolio' )
						: __( 'The feed was turned off in zFolio.', 'bristlecone-zfolio' ),
				);

			case Response::GONE:
				Connections::update_state(
					$id,
					$common + array(
						'gone_current' => $response->current,
						'last_error'   => 'version retired',
					)
				);
				return array(
					'ok'      => false,
					'message' => __( 'zFolio no longer serves this version of the feed. Update the plugin.', 'bristlecone-zfolio' ),
				);

			case Response::NOT_FOUND:
				// Never take content down on a polling 404 alone.
				Connections::update_state( $id, $common + array( 'last_error' => 'not found' ) );
				return array(
					'ok'      => false,
					'message' => __( 'zFolio doesn’t recognize this feed address. If the owner rotated it, paste the new address.', 'bristlecone-zfolio' ),
				);

			case Response::OK:
				break;

			default:
				Connections::update_state( $id, $common + array( 'last_error' => $response->message ) );
				return array(
					'ok'      => false,
					'message' => __( 'zFolio couldn’t be reached. The site keeps showing what it has.', 'bristlecone-zfolio' ),
					'retry'   => true,
				);
		}

		$manifest = Validator::manifest( (array) $response->data );

		if ( null === $manifest ) {
			Connections::update_state( $id, $common + array( 'last_error' => 'invalid manifest' ) );
			return array(
				'ok'      => false,
				'message' => __( 'zFolio sent a feed the plugin can’t read. The site keeps showing what it has.', 'bristlecone-zfolio' ),
				'retry'   => true,
			);
		}

		if ( '' !== $state['feed_id'] && $manifest['feedId'] !== $state['feed_id'] ) {
			Connections::update_state( $id, $common + array( 'last_error' => 'different feed' ) );
			return array(
				'ok'      => false,
				'message' => __( 'This address now points at a different feed. Clear the connection’s content before using it.', 'bristlecone-zfolio' ),
			);
		}

		if ( Plan::is_stale( $manifest['revision'], (int) $state['revision'] ) ) {
			Connections::update_state( $id, $common + array( 'last_error' => '' ) + self::reactivate( $id, $state ) );
			return array(
				'ok'      => true,
				'message' => __( 'No changes since the last update.', 'bristlecone-zfolio' ),
			);
		}

		$stored   = Store::manifest_rows( $id );
		$plan     = Plan::make( $manifest['pages'], array_map( static fn ( array $row ): string => $row['hash'], $stored ) );
		$failures = self::current_failures( (array) $state['failures'], $manifest['pages'] );

		foreach ( $manifest['pages'] as $page_id => $page ) {
			if ( Store::meta_changed( $page, $stored[ $page_id ] ?? null ) ) {
				Store::upsert_meta( $id, $page );
			}
		}

		$deleted = $plan->delete ? Store::delete_except( $id, array_keys( $manifest['pages'] ), array_keys( $stored ) ) : array();

		// Pages that keep failing for this hash wait for the next revision, so
		// the rest of the feed can settle; pages that failed before go last.
		$given_up = array_values( array_filter( $plan->fetch, static fn ( string $page_id ): bool => ( $failures[ $page_id ]['count'] ?? 0 ) >= self::ATTEMPTS ) );
		$queue    = array_values( array_diff( $plan->fetch, $given_up ) );
		usort( $queue, static fn ( string $a, string $b ): int => ( $failures[ $a ]['count'] ?? 0 ) <=> ( $failures[ $b ]['count'] ?? 0 ) );

		$fetched = array();
		$failed  = 0;
		$limited = false;
		$started = microtime( true );

		foreach ( $queue as $page_id ) {
			if ( count( $fetched ) + $failed >= self::BATCH || microtime( true ) - $started > self::BUDGET ) {
				break;
			}

			$page = $this->client->page( (string) $connection['address'], $page_id );

			if ( Response::RATE_LIMITED === $page->outcome ) {
				Connections::update_state( $id, array( 'retry_until' => time() + $page->retry_after ) );
				$limited = true;
				break;
			}

			if ( $this->store_page( $id, $page, $manifest, $page_id ) ) {
				$fetched[] = $page_id;
				unset( $failures[ $page_id ] );
			} else {
				++$failed;
				$failures[ $page_id ] = array(
					'hash'  => (string) $manifest['pages'][ $page_id ]['hash'],
					'count' => ( $failures[ $page_id ]['count'] ?? 0 ) + 1,
				);
			}
		}

		$given_up  = array_values( array_filter( $plan->fetch, static fn ( string $page_id ): bool => ( $failures[ $page_id ]['count'] ?? 0 ) >= self::ATTEMPTS ) );
		$remaining = count( $plan->fetch ) - count( $fetched ) - count( $given_up );
		$complete  = $remaining <= 0;
		$problem   = '';

		if ( $given_up ) {
			/* translators: %d: number of pages. */
			$problem = sprintf( _n( '%d page could not be fetched', '%d pages could not be fetched', count( $given_up ), 'bristlecone-zfolio' ), count( $given_up ) );
		} elseif ( $failed > 0 || $limited ) {
			$problem = __( 'some pages could not be fetched yet', 'bristlecone-zfolio' );
		}

		$was_down = in_array( $state['status'], array( 'paused', 'revoked' ), true );

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
				'failures'     => $failures,
				'last_changed' => ( $fetched || $deleted ) ? $now : (int) $state['last_changed'],
				'last_error'   => $problem,
			)
		);

		if ( $fetched || $deleted || 'active' !== $state['status'] ) {
			Cache::purge_pages( $id, array_merge( $fetched, $deleted ), $was_down || 'active' !== $state['status'] );
		}

		if ( ! $complete ) {
			// The contract allows one feed request every 5 minutes per connection.
			Cron::schedule( $id, self::CONTINUE_AFTER );
		} else {
			/**
			 * Fires when a connection's stored pages match its feed.
			 *
			 * @param string $id Connection id.
			 */
			do_action( 'bristlecone_zfolio_synced', $id );
		}

		/* translators: 1: pages updated, 2: pages removed. */
		$message = sprintf( __( 'Updated %1$d pages and removed %2$d.', 'bristlecone-zfolio' ), count( $fetched ), count( $deleted ) );

		if ( ! $complete ) {
			/* translators: %d: pages still to fetch. */
			$message .= ' ' . sprintf( __( '%d more will follow in a few minutes.', 'bristlecone-zfolio' ), $remaining );
		}

		if ( $given_up ) {
			/* translators: %d: number of pages. */
			$message .= ' ' . sprintf( _n( '%d page could not be fetched and will be tried again when the feed next changes.', '%d pages could not be fetched and will be tried again when the feed next changes.', count( $given_up ), 'bristlecone-zfolio' ), count( $given_up ) );
		} elseif ( $failed > 0 || $limited ) {
			$message .= ' ' . __( 'Some pages could not be fetched this time and will be tried again.', 'bristlecone-zfolio' );
		}

		return array(
			'ok'      => '' === $problem,
			'message' => $message,
		);
	}

	/**
	 * Fetch one page again now (per-page Refresh), whatever its hash.
	 *
	 * @param string $id      Connection id.
	 * @param string $page_id Page id.
	 * @return array{ok:bool,message:string}
	 */
	public function refresh_page( string $id, string $page_id ): array {
		$connection = Connections::get( $id );
		$state      = Connections::state( $id );

		if ( null === $connection || '' === $state['feed_id'] || null === Store::get( $id, $page_id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That page isn’t stored yet. Choose Refresh Now first.', 'bristlecone-zfolio' ),
			);
		}

		if ( (int) $state['retry_until'] > time() || ! self::lock( $id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'An update is running or zFolio asked the site to wait. Try again in a minute.', 'bristlecone-zfolio' ),
			);
		}

		try {
			$response = $this->client->page( (string) $connection['address'], $page_id );
			$manifest = array(
				'feedId'           => (string) $state['feed_id'],
				'allowlistVersion' => Sanitizer::ALLOWLIST_VERSION,
			);

			if ( ! $this->store_page( $id, $response, $manifest, $page_id ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'zFolio didn’t send that page. The site keeps showing what it has.', 'bristlecone-zfolio' ),
				);
			}
		} finally {
			self::unlock( $id );
		}

		$failures = (array) Connections::state( $id )['failures'];

		if ( isset( $failures[ $page_id ] ) ) {
			unset( $failures[ $page_id ] );
			Connections::update_state(
				$id,
				$failures ? array( 'failures' => $failures ) : array(
					'failures'   => array(),
					'last_error' => '',
				)
			);
		}

		Cache::purge_pages( $id, array( $page_id ) );

		return array(
			'ok'      => true,
			'message' => __( 'Page updated.', 'bristlecone-zfolio' ),
		);
	}

	/**
	 * Validate, clean, and save one page response.
	 *
	 * @param string              $id       Connection id.
	 * @param Response            $response Page response.
	 * @param array<string,mixed> $manifest feedId and allowlistVersion.
	 * @param string              $page_id  Page asked for.
	 */
	private function store_page( string $id, Response $response, array $manifest, string $page_id ): bool {
		$valid = Response::OK === $response->outcome ? Validator::page( (array) $response->data, (string) $manifest['feedId'] ) : null;

		if ( null === $valid || $valid['id'] !== $page_id ) {
			return false;
		}

		$valid['html'] = Sanitizer::clean( $valid['html'] );

		return Store::save_content( $id, $valid, min( (int) $manifest['allowlistVersion'], Sanitizer::ALLOWLIST_VERSION ) );
	}

	/**
	 * Failure counts that still apply: same page, same hash.
	 *
	 * @param array<string,mixed>               $failures Stored failures.
	 * @param array<string,array<string,mixed>> $pages    Manifest pages.
	 * @return array<string,array{hash:string,count:int}>
	 */
	private static function current_failures( array $failures, array $pages ): array {
		$out = array();

		foreach ( $failures as $page_id => $failure ) {
			if ( is_array( $failure ) && isset( $pages[ $page_id ] ) && ( $failure['hash'] ?? '' ) === $pages[ $page_id ]['hash'] ) {
				$out[ (string) $page_id ] = array(
					'hash'  => (string) $failure['hash'],
					'count' => (int) ( $failure['count'] ?? 0 ),
				);
			}
		}

		return $out;
	}

	/**
	 * State for a feed that serves again (304, or a stale 200): reactivate a
	 * paused or revoked connection and purge what its takedown hid.
	 *
	 * @param string              $id    Connection id.
	 * @param array<string,mixed> $state State.
	 * @return array<string,string>
	 */
	private static function reactivate( string $id, array $state ): array {
		if ( ! in_array( $state['status'], array( 'paused', 'revoked' ), true ) ) {
			return array();
		}

		Cache::purge_connection( $id );

		return array( 'status' => 'active' );
	}

	/**
	 * Lock tokens this request holds, by connection.
	 *
	 * @var array<string,string>
	 */
	private static array $tokens = array();

	/**
	 * Take the per-connection lock. A lock older than five minutes is stale
	 * and may be taken over, but only by one process: the takeover replaces
	 * the value it read, and fails if someone else got there first.
	 *
	 * @param string $id Connection id.
	 */
	private static function lock( string $id ): bool {
		global $wpdb;

		$name  = 'bristlecone_zfolio_lock_' . $id;
		$token = time() . ':' . wp_generate_password( 16, false, false );

		if ( add_option( $name, $token, '', false ) ) {
			self::$tokens[ $id ] = $token;
			return true;
		}

		$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( (int) strtok( $current, ':' ) >= time() - 300 ) {
			return false;
		}

		$taken = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap on the lock row.
			$wpdb->options,
			array( 'option_value' => $token ),
			array(
				'option_name'  => $name,
				'option_value' => $current,
			)
		);
		wp_cache_delete( $name, 'options' );

		if ( 1 !== $taken ) {
			return false;
		}

		self::$tokens[ $id ] = $token;

		return true;
	}

	/**
	 * Release the lock, only if this process still holds it.
	 *
	 * @param string $id Connection id.
	 */
	private static function unlock( string $id ): void {
		global $wpdb;

		if ( ! isset( self::$tokens[ $id ] ) ) {
			return;
		}

		$name = 'bristlecone_zfolio_lock_' . $id;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release only the holder's lock row.
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => self::$tokens[ $id ],
			)
		);
		wp_cache_delete( $name, 'options' );
		unset( self::$tokens[ $id ] );
	}
}
