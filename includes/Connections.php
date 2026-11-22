<?php
/**
 * Connections: the feeds a site reads, and what the plugin knows about each.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * Settings live in one option, runtime state in another, so frequent state
 * writes never touch the settings an admin saved.
 */
final class Connections {

	public const OPTION       = 'bristlecone_zfolio_connections';
	public const STATE_OPTION = 'bristlecone_zfolio_state';

	/**
	 * Check for Updates choices, in minutes, in the order the screen lists
	 * them. 0 means only when zFolio sends a webhook.
	 */
	public const INTERVALS = array( 5, 15, 30, 60, self::DAILY, self::WEEKLY, self::WEBHOOK_ONLY );

	/**
	 * Only when zFolio sends a webhook: no scheduled checks.
	 */
	public const WEBHOOK_ONLY = 0;

	/**
	 * Once a day, in minutes.
	 */
	public const DAILY = 1440;

	/**
	 * Once a week, in minutes.
	 */
	public const WEEKLY = 10080;

	/**
	 * Days without a good webhook before a webhook-only connection warns.
	 */
	public const QUIET_DAYS = 7;

	/**
	 * Every connection's settings, keyed by id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $id => $connection ) {
			if ( is_array( $connection ) && self::valid_id( (string) $id ) ) {
				$out[ (string) $id ] = self::with_defaults( $connection ) + array( 'id' => (string) $id );
			}
		}

		return $out;
	}

	/**
	 * One connection's settings.
	 *
	 * @param string $id Connection id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Save one connection's settings.
	 *
	 * @param string              $id         Connection id.
	 * @param array<string,mixed> $connection Settings.
	 */
	public static function save( string $id, array $connection ): void {
		$all        = self::all();
		$all[ $id ] = self::with_defaults( $connection );
		unset( $all[ $id ]['id'] );

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Remove a connection and its state.
	 *
	 * @param string $id Connection id.
	 */
	public static function delete( string $id ): void {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		delete_option( self::state_option( $id ) );

		$legacy = self::all_state();

		if ( isset( $legacy[ $id ] ) ) {
			unset( $legacy[ $id ] );
			update_option( self::STATE_OPTION, $legacy, false );
		}
	}

	/**
	 * A new connection id.
	 */
	public static function new_id(): string {
		do {
			$id = 'c' . strtolower( wp_generate_password( 9, false, false ) );
		} while ( null !== self::get( $id ) );

		return $id;
	}

	/**
	 * Whether a string is a connection id.
	 *
	 * @param string $id Candidate.
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^c[a-z0-9]{9}$/', $id );
	}

	/**
	 * One connection's runtime state.
	 *
	 * @param string $id Connection id.
	 * @return array<string,mixed>
	 */
	public static function state( string $id ): array {
		$state = get_option( self::state_option( $id ), null );

		if ( ! is_array( $state ) ) {
			// Before 1.0.0 was released, every connection shared one option.
			$state = self::all_state()[ $id ] ?? array();
		}

		return $state + array(
			'status'            => 'new',
			'feed_id'           => '',
			'title'             => '',
			'kind'              => '',
			'revision'          => -1,
			'etag'              => '',
			'folders'           => array(),
			'last_checked'      => 0,
			'last_changed'      => 0,
			'last_error'        => '',
			'retry_until'       => 0,
			'deprecation'       => '',
			'sunset'            => '',
			'gone_current'      => 0,
			'pending'           => false,
			'last_webhook'      => 0,
			'last_webhook_type' => '',
			'address_rotated'   => 0,
			'secret_rotated'    => 0,
			'failures'          => array(),
			'retries'           => 0,
		);
	}

	/**
	 * Merge values into one connection's state.
	 *
	 * @param string              $id     Connection id.
	 * @param array<string,mixed> $values Values.
	 */
	public static function update_state( string $id, array $values ): void {
		$before = self::state( $id );

		// One option per connection, so a write never replaces another
		// connection's state.
		update_option( self::state_option( $id ), array_merge( $before, $values ), false );

		if ( isset( $values['status'] ) && $values['status'] !== $before['status'] ) {
			/**
			 * Fires when a connection's status changes (active, paused, revoked).
			 *
			 * @param string $id     Connection id.
			 * @param string $status New status.
			 */
			do_action( 'bristlecone_zfolio_status_changed', $id, (string) $values['status'] );
		}
	}

	/**
	 * The option that holds one connection's state.
	 *
	 * @param string $id Connection id.
	 */
	private static function state_option( string $id ): string {
		return self::STATE_OPTION . '_' . $id;
	}

	/**
	 * Every connection's raw state, as stored before 1.0.0 (read only).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function all_state(): array {
		$value = get_option( self::STATE_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Whether a connection may use webhook-only: it has a webhook secret and
	 * has received at least one correctly signed event with it.
	 *
	 * @param array<string,mixed> $connection Settings.
	 * @param array<string,mixed> $state      State.
	 */
	public static function can_use_webhook_only( array $connection, array $state ): bool {
		return '' !== (string) ( $connection['webhook_secret'] ?? '' ) && (int) ( $state['last_webhook'] ?? 0 ) > 0;
	}

	/**
	 * Whether a webhook-only connection has gone too long without a good
	 * webhook. A quiet feed sends none either, so this is a prompt to check,
	 * not proof of a fault.
	 *
	 * @param array<string,mixed> $connection Settings.
	 * @param array<string,mixed> $state      State.
	 * @param int                 $now        Unix time.
	 */
	public static function webhook_quiet( array $connection, array $state, int $now ): bool {
		if ( self::WEBHOOK_ONLY !== (int) ( $connection['interval'] ?? 15 ) ) {
			return false;
		}

		$last = (int) ( $state['last_webhook'] ?? 0 );

		return $last <= 0 || $now - $last > self::QUIET_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Settings with defaults filled in.
	 *
	 * @param array<string,mixed> $connection Settings.
	 * @return array<string,mixed>
	 */
	private static function with_defaults( array $connection ): array {
		$connection += array(
			'label'          => '',
			'address'        => '',
			'interval'       => 15,
			'takedown'       => 'hide',
			'math'           => 'mathml',
			'webhook_secret' => '',
			'mirror'         => false,
			'mirror_slug'    => '',
		);

		if ( ! in_array( (int) $connection['interval'], self::INTERVALS, true ) ) {
			$connection['interval'] = 15;
		}

		$connection['interval'] = (int) $connection['interval'];
		$connection['takedown'] = 'keep' === $connection['takedown'] ? 'keep' : 'hide';
		$connection['math']     = 'tex' === $connection['math'] ? 'tex' : 'mathml';

		$connection['mirror']      = ! empty( $connection['mirror'] );
		$connection['mirror_slug'] = sanitize_title( (string) $connection['mirror_slug'] );

		if ( ! Webhook\Signature::valid_secret( (string) $connection['webhook_secret'] ) ) {
			$connection['webhook_secret'] = '';
		}

		// Webhook-only needs a webhook to listen for.
		if ( self::WEBHOOK_ONLY === $connection['interval'] && '' === $connection['webhook_secret'] ) {
			$connection['interval'] = 15;
		}

		return $connection;
	}
}
