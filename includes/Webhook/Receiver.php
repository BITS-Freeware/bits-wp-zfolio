<?php
/**
 * The webhook endpoint: POST /wp-json/bristlecone-zfolio/v1/hooks/<connection>.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Webhook;

use Bristlecone\Zfolio\Cache;
use Bristlecone\Zfolio\Connections;
use Bristlecone\Zfolio\Cron;
use Bristlecone\Zfolio\Rest;
use Bristlecone\Zfolio\Store;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Checks the signature, drops repeats, acts on the event, and answers `202`
 * at once. Anything that needs the feed is left to a sync that cron starts
 * right away, so zFolio never waits on it. Polling stays on as the backstop.
 *
 * Answers: `404` for a connection without webhooks set up and `401` for a
 * bad signature (both turn the endpoint off in zFolio, which is right: the
 * owner sees why), `400` for a stale timestamp or a body that isn't an event
 * of this feed (retried), and `202` for everything accepted or skipped.
 */
final class Receiver {

	/**
	 * How long an event id is remembered, in seconds.
	 */
	public const REMEMBER = 25 * HOUR_IN_SECONDS;

	/**
	 * Delay before checking the feed after a pause or turn-off, so zFolio's
	 * edge cache (up to 60 seconds) has caught up.
	 */
	public const CONFIRM_DELAY = 90;

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Register the route.
	 */
	public static function routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/hooks/(?P<connection>c[a-z0-9]{9})',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'receive' ),
				// Public by design: the signature is the authentication.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The endpoint's address for a connection.
	 *
	 * @param string $id Connection id.
	 */
	public static function url( string $id ): string {
		return rest_url( Rest::NAMESPACE . '/hooks/' . $id );
	}

	/**
	 * Handle a delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$id         = (string) $request->get_param( 'connection' );
		$connection = Connections::get( $id );
		$secret     = (string) ( $connection['webhook_secret'] ?? '' );

		if ( null === $connection || '' === $secret ) {
			return self::answer( 404, 'not_found' );
		}

		$body    = $request->get_body();
		$checked = Signature::verify( (string) $request->get_header( 'zfolio_signature' ), $body, $secret, time() );

		if ( Signature::EXPIRED === $checked ) {
			return self::answer( 400, 'timestamp_out_of_range' );
		}

		if ( Signature::OK !== $checked ) {
			return self::answer( 401, 'bad_signature' );
		}

		$event = Event::parse( $body );

		if ( null === $event ) {
			return self::answer( 400, 'invalid_event' );
		}

		$state = Connections::state( $id );

		if ( '' !== $state['feed_id'] && $event['feedId'] !== $state['feed_id'] ) {
			return self::answer( 400, 'different_feed' );
		}

		$seen = 'bristlecone_zfolio_evt_' . $id . '_' . substr( $event['id'], 4 );

		if ( false !== get_transient( $seen ) ) {
			return self::answer( 202, 'duplicate' );
		}

		set_transient( $seen, 1, self::REMEMBER );

		self::apply( $id, $event, $state );

		Connections::update_state(
			$id,
			array(
				'last_webhook'      => time(),
				'last_webhook_type' => $event['type'],
			)
		);

		return self::answer( 202, 'accepted' );
	}

	/**
	 * Act on an event.
	 *
	 * @param string              $id    Connection id.
	 * @param array<string,mixed> $event Parsed event.
	 * @param array<string,mixed> $state Connection state.
	 */
	public static function apply( string $id, array $event, array $state ): void {
		$known = '' !== $state['feed_id'];
		$fresh = null === $event['revision'] || ! $known || (int) $event['revision'] > (int) $state['revision'] || ! empty( $state['pending'] );

		switch ( $event['type'] ) {
			case 'feed.updated':
				if ( $fresh ) {
					if ( $known && $event['pages'] ) {
						self::remove( $id, $event['pages'] );
					}

					Cron::now( $id );
				}
				break;

			case 'feed.page_removed':
				if ( $fresh ) {
					if ( $known ) {
						self::remove( $id, $event['pages'] );
					}

					Cron::now( $id );
				}
				break;

			case 'feed.paused':
			case 'feed.revoked':
				if ( $known ) {
					$status = 'feed.paused' === $event['type'] ? 'paused' : 'revoked';

					if ( $status !== $state['status'] ) {
						Connections::update_state( $id, array( 'status' => $status ) );
						Cache::purge_connection( $id );
					}
				}

				// The feed has the last word: a late event can't keep content down.
				Cron::schedule( $id, self::CONFIRM_DELAY );
				break;

			case 'feed.resumed':
				Cron::now( $id );
				break;

			case 'feed.rotated':
				if ( 'address' === $event['what'] ) {
					Connections::update_state( $id, array( 'address_rotated' => max( 1, (int) $event['until'] ) ) );
					Cron::now( $id );
				} else {
					Connections::update_state( $id, array( 'secret_rotated' => max( 1, (int) $event['until'] ) ) );
				}
				break;

			default:
				// feed.test, and types added later within v1: acknowledge only.
				break;
		}
	}

	/**
	 * Delete pages a signed event says are gone, and purge the posts that
	 * showed them.
	 *
	 * @param string            $id       Connection id.
	 * @param array<int,string> $page_ids Page ids.
	 */
	private static function remove( string $id, array $page_ids ): void {
		Store::delete_pages( $id, $page_ids );
		Cache::purge_pages( $id, $page_ids );
	}

	/**
	 * A small JSON answer. zFolio ignores the body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Short code.
	 */
	private static function answer( int $status, string $code ): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => $code ), $status );
	}
}
