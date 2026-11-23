<?php
/**
 * Polling with WordPress cron, the backstop for missed webhooks.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * One single event per connection, rescheduled after every run with the
 * connection's interval plus jitter. Cron events belong to each site, so this
 * is Multisite-safe without extra work.
 *
 * A webhook-only connection (interval 0) has no regular event. One-off syncs
 * still go through the same hook: a webhook's sync, Refresh Now, the check
 * after a pause, continuing a large feed, waiting out a 429 or 503, and a
 * short series of retries after zFolio couldn't be reached.
 */
final class Cron {

	public const HOOK = 'bristlecone_zfolio_poll';

	/**
	 * Seconds before retrying when another sync holds the lock.
	 */
	public const BUSY_RETRY = 60;

	/**
	 * Retries after a failed sync on a webhook-only connection. They back off
	 * from Sync::CONTINUE_AFTER: 5, 10, 20, 40, then 80 minutes.
	 */
	public const MAX_RETRIES = 5;

	/**
	 * Seconds after which an inline sync's safety event runs, in case the
	 * request dies mid-sync. Longer than the sync lock's five minutes.
	 */
	public const INLINE_SAFETY = 360;

	/**
	 * Connections waiting to sync once the current response is sent.
	 *
	 * @var array<string,true>
	 */
	private static array $after_response = array();

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'poll' ) );
		add_action( 'init', array( self::class, 'ensure' ), 20 );
	}

	/**
	 * Cron callback.
	 *
	 * @param string $id Connection id.
	 */
	public static function poll( $id ): void {
		self::run( (string) $id );
	}

	/**
	 * Make sure every polling connection has its next poll scheduled.
	 */
	public static function ensure(): void {
		foreach ( Connections::all() as $id => $connection ) {
			if ( Connections::WEBHOOK_ONLY === (int) $connection['interval'] ) {
				continue;
			}

			if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				self::schedule( $id, random_int( 10, 120 ) );
			}
		}
	}

	/**
	 * Schedule a connection's next poll, replacing any pending one.
	 *
	 * With no delay this is the regular poll: the interval plus jitter. A
	 * webhook-only connection has none, so it keeps only the one-off event
	 * that unfinished work needs (more pages to fetch, or a wait zFolio asked
	 * for) and otherwise clears its event.
	 *
	 * @param string   $id    Connection id.
	 * @param int|null $delay Seconds from now; null for the regular poll.
	 */
	public static function schedule( string $id, ?int $delay = null ): void {
		$connection = Connections::get( $id );

		if ( null === $connection ) {
			return;
		}

		$state = Connections::state( $id );

		if ( null === $delay ) {
			$delay = self::regular_delay( (int) $connection['interval'], ! empty( $state['pending'] ), (int) $state['retry_until'], time() );

			if ( null === $delay ) {
				wp_clear_scheduled_hook( self::HOOK, array( $id ) );
				return;
			}

			if ( Connections::WEBHOOK_ONLY === (int) $connection['interval'] && wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				// Unfinished work already has its event.
				return;
			}
		}

		$at = max( time() + max( 5, $delay ), (int) $state['retry_until'] );

		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		wp_schedule_single_event( $at, self::HOOK, array( $id ) );
	}

	/**
	 * Seconds until the regular poll, or null for none.
	 *
	 * @param int  $interval    Minutes; 0 for webhook-only.
	 * @param bool $pending     Whether pages are still to be fetched.
	 * @param int  $retry_until Unix time zFolio asked the site to wait until.
	 * @param int  $now         Unix time.
	 */
	public static function regular_delay( int $interval, bool $pending, int $retry_until, int $now ): ?int {
		if ( Connections::WEBHOOK_ONLY !== $interval ) {
			return $interval * MINUTE_IN_SECONDS + random_int( 0, 90 );
		}

		if ( $retry_until > $now ) {
			return $retry_until - $now;
		}

		return $pending ? Sync::CONTINUE_AFTER : null;
	}

	/**
	 * Seconds before retry number $attempt (from 1) on a webhook-only
	 * connection, or null once the retries are used up.
	 *
	 * @param int $attempt Retry number.
	 */
	public static function retry_delay( int $attempt ): ?int {
		if ( $attempt < 1 || $attempt > self::MAX_RETRIES ) {
			return null;
		}

		return Sync::CONTINUE_AFTER * ( 2 ** ( $attempt - 1 ) );
	}

	/**
	 * Run a connection's sync as soon as possible, unless zFolio asked the
	 * site to wait.
	 *
	 * Normally this schedules the sync for now and nudges WordPress cron.
	 * When the site turned WordPress cron off (DISABLE_WP_CRON), the sync runs
	 * at the end of this request instead, after the response has been sent,
	 * where PHP can do that (PHP-FPM or LiteSpeed). Otherwise it is left for
	 * the site's real cron.
	 *
	 * @param string $id Connection id.
	 */
	public static function now( string $id ): void {
		if ( null === Connections::get( $id ) ) {
			return;
		}

		$state = Connections::state( $id );

		if ( (int) $state['retry_until'] > time() ) {
			self::schedule( $id, 0 );
			return;
		}

		if ( 0 !== (int) $state['retries'] ) {
			// A new event starts a new series of retries.
			Connections::update_state( $id, array( 'retries' => 0 ) );
		}

		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		wp_schedule_single_event( time(), self::HOOK, array( $id ) );

		if ( wp_doing_cron() ) {
			return;
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			if ( self::can_finish_response() ) {
				self::run_after_response( $id );
			}
			return;
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Remove a connection's events.
	 *
	 * @param string $id Connection id.
	 */
	public static function unschedule( string $id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	/**
	 * Sync the connections queued by now(), after the response is sent.
	 */
	public static function finish_response(): void {
		$ids                  = array_keys( self::$after_response );
		self::$after_response = array();

		if ( ! $ids ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			return;
		}

		ignore_user_abort( true );

		foreach ( $ids as $id ) {
			// Swap the event for now with a later one, so a request that dies
			// mid-sync still leaves the sync to real cron.
			$safety = time() + self::INLINE_SAFETY;
			wp_clear_scheduled_hook( self::HOOK, array( $id ) );
			wp_schedule_single_event( $safety, self::HOOK, array( $id ) );

			self::run( $id, $safety );
		}
	}

	/**
	 * Sync a connection, then schedule what comes next.
	 *
	 * @param string $id     Connection id.
	 * @param int    $safety Timestamp of an inline sync's safety event, or 0.
	 */
	private static function run( string $id, int $safety = 0 ): void {
		$connection = Connections::get( $id );

		if ( null === $connection ) {
			return;
		}

		$result = ( new Sync() )->run( $id );

		if ( $safety > 0 ) {
			// Sync replaces the event when it needs one; drop the safety one only.
			wp_unschedule_event( $safety, self::HOOK, array( $id ) );
		}

		if ( ! empty( $result['busy'] ) ) {
			// Another sync holds the lock and may have read the feed before
			// the change that started this one.
			self::schedule( $id, self::BUSY_RETRY );
			return;
		}

		if ( Connections::WEBHOOK_ONLY === (int) $connection['interval'] ) {
			$retries = (int) Connections::state( $id )['retries'];

			if ( ! empty( $result['retry'] ) ) {
				$delay = self::retry_delay( $retries + 1 );
				Connections::update_state( $id, array( 'retries' => $retries + 1 ) );

				if ( null !== $delay ) {
					self::schedule( $id, $delay );
					return;
				}
			} elseif ( 0 !== $retries ) {
				Connections::update_state( $id, array( 'retries' => 0 ) );
			}
		}

		if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			self::schedule( $id );
		}
	}

	/**
	 * Whether PHP can send the response and keep running.
	 */
	private static function can_finish_response(): bool {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/**
	 * Queue a connection's sync for after the response.
	 *
	 * @param string $id Connection id.
	 */
	private static function run_after_response( string $id ): void {
		if ( ! self::$after_response ) {
			// After WordPress flushes its output buffers at priority 1.
			add_action( 'shutdown', array( self::class, 'finish_response' ), 100 );
		}

		self::$after_response[ $id ] = true;
	}
}
