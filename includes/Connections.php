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

	public const INTERVALS = array( 5, 15, 30, 60 );

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

		$state = self::all_state();
		unset( $state[ $id ] );
		update_option( self::STATE_OPTION, $state, false );
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
		$state = self::all_state()[ $id ] ?? array();

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
		);
	}

	/**
	 * Merge values into one connection's state.
	 *
	 * @param string              $id     Connection id.
	 * @param array<string,mixed> $values Values.
	 */
	public static function update_state( string $id, array $values ): void {
		$state        = self::all_state();
		$state[ $id ] = array_merge( self::state( $id ), $values );

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Every connection's raw state.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function all_state(): array {
		$value = get_option( self::STATE_OPTION, array() );

		return is_array( $value ) ? $value : array();
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
		);

		if ( ! in_array( (int) $connection['interval'], self::INTERVALS, true ) ) {
			$connection['interval'] = 15;
		}

		$connection['interval'] = (int) $connection['interval'];
		$connection['takedown'] = 'keep' === $connection['takedown'] ? 'keep' : 'hide';
		$connection['math']     = 'tex' === $connection['math'] ? 'tex' : 'mathml';

		if ( ! Webhook\Signature::valid_secret( (string) $connection['webhook_secret'] ) ) {
			$connection['webhook_secret'] = '';
		}

		return $connection;
	}
}
