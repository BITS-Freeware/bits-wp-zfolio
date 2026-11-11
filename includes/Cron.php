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
 */
final class Cron {

	public const HOOK = 'bristlecone_zfolio_poll';

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
		$id = (string) $id;

		if ( null === Connections::get( $id ) ) {
			return;
		}

		( new Sync() )->run( $id );

		if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			self::schedule( $id );
		}
	}

	/**
	 * Make sure every connection has its next poll scheduled.
	 */
	public static function ensure(): void {
		foreach ( array_keys( Connections::all() ) as $id ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				self::schedule( $id, random_int( 10, 120 ) );
			}
		}
	}

	/**
	 * Schedule a connection's next poll, replacing any pending one.
	 *
	 * @param string   $id    Connection id.
	 * @param int|null $delay Seconds from now; null for the interval plus jitter.
	 */
	public static function schedule( string $id, ?int $delay = null ): void {
		$connection = Connections::get( $id );

		if ( null === $connection ) {
			return;
		}

		if ( null === $delay ) {
			$delay = (int) $connection['interval'] * MINUTE_IN_SECONDS + random_int( 0, 90 );
		}

		$state = Connections::state( $id );
		$at    = max( time() + max( 5, $delay ), (int) $state['retry_until'] );

		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		wp_schedule_single_event( $at, self::HOOK, array( $id ) );
	}

	/**
	 * Run a connection's sync as soon as possible: schedule it for now and
	 * nudge WordPress cron, unless zFolio asked the site to wait.
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

		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		wp_schedule_single_event( time(), self::HOOK, array( $id ) );

		if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
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
}
