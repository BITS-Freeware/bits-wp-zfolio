<?php
/**
 * What a sync must do, worked out from the manifest and the store.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * Pure logic, no WordPress, so it is easy to test.
 */
final class Plan {

	/**
	 * Constructor.
	 *
	 * @param list<string> $fetch  Page ids to fetch, in feed order.
	 * @param list<string> $delete Stored page ids the manifest no longer lists.
	 */
	public function __construct(
		public readonly array $fetch,
		public readonly array $delete,
	) {}

	/**
	 * Compare a validated manifest with stored hashes.
	 *
	 * The manifest is always complete in v1, so a stored page it doesn't list
	 * is gone. A page is fetched when it's new, never fetched, or its hash
	 * changed.
	 *
	 * @param array<string,array<string,mixed>> $pages  Manifest pages, by id.
	 * @param array<string,string>              $stored Stored hashes, by id ('' = never fetched).
	 */
	public static function make( array $pages, array $stored ): self {
		$fetch = array();

		foreach ( $pages as $id => $page ) {
			if ( ( $stored[ $id ] ?? '' ) !== $page['hash'] ) {
				$fetch[] = (string) $id;
			}
		}

		return new self( $fetch, array_values( array_diff( array_keys( $stored ), array_keys( $pages ) ) ) );
	}

	/**
	 * Whether an event or manifest revision is older than what's stored.
	 *
	 * @param int $incoming Incoming revision.
	 * @param int $stored   Stored revision (-1 when none).
	 */
	public static function is_stale( int $incoming, int $stored ): bool {
		return $stored >= 0 && $incoming < $stored;
	}
}
