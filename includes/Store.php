<?php
/**
 * The plugin's own table of fetched pages.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

/**
 * Pages live in the site's database, so a zFolio outage never empties the
 * site. One table per site, so Multisite keeps each site's content apart.
 */
final class Store {

	public const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'bristlecone_zfolio_db_version';

	/**
	 * Table name for the current site.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bristlecone_zfolio_pages';
	}

	/**
	 * Create or upgrade the table when needed. Cheap when nothing changed.
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id varchar(16) NOT NULL,
			page_id varchar(32) NOT NULL,
			slug varchar(200) NOT NULL,
			title text NOT NULL,
			description text NOT NULL,
			parents text NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			hash char(64) NOT NULL DEFAULT '',
			html longtext NOT NULL,
			headings longtext NOT NULL,
			lang varchar(35) NOT NULL DEFAULT '',
			published_at varchar(32) NOT NULL DEFAULT '',
			updated_at varchar(32) NOT NULL DEFAULT '',
			fetched_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			allowlist_version smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_page (connection_id,page_id),
			KEY connection_slug (connection_id,slug(191))
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Drop the table and its version (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table name is ours.
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Stored hashes of a connection's pages, keyed by page id.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,string>
	 */
	public static function hashes( string $connection_id ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, hash, html FROM {$table} WHERE connection_id = %s", $connection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$out = array();

		foreach ( (array) $rows as $row ) {
			// A row with no HTML yet has never been fetched.
			$out[ (string) $row['page_id'] ] = '' === (string) $row['html'] ? '' : (string) $row['hash'];
		}

		return $out;
	}

	/**
	 * Update a page's manifest fields, creating the row when it is new.
	 *
	 * @param string              $connection_id Connection id.
	 * @param array<string,mixed> $meta          Validated manifest page.
	 */
	public static function upsert_meta( string $connection_id, array $meta ): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, page_id, slug, title, description, parents, sort_order, hash, html, headings) VALUES (%s, %s, %s, %s, %s, %s, %d, '', '', '[]') ON DUPLICATE KEY UPDATE slug = VALUES(slug), title = VALUES(title), description = VALUES(description), parents = VALUES(parents), sort_order = VALUES(sort_order)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$connection_id,
				$meta['id'],
				$meta['slug'],
				$meta['title'],
				$meta['description'],
				(string) wp_json_encode( $meta['parents'] ),
				$meta['order']
			)
		);
	}

	/**
	 * Save fetched page content.
	 *
	 * @param string              $connection_id Connection id.
	 * @param array<string,mixed> $page          Validated page, html already sanitized.
	 * @param int                 $allowlist     allowlistVersion the HTML was cleaned with.
	 */
	public static function save_content( string $connection_id, array $page, int $allowlist ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'html'              => $page['html'],
				'headings'          => (string) wp_json_encode( $page['headings'] ),
				'hash'              => $page['hash'],
				'lang'              => $page['lang'],
				'published_at'      => $page['publishedAt'],
				'updated_at'        => $page['updatedAt'],
				'fetched_at'        => current_time( 'mysql', true ),
				'allowlist_version' => $allowlist,
			),
			array(
				'connection_id' => $connection_id,
				'page_id'       => $page['id'],
			)
		);
	}

	/**
	 * Delete pages of a connection that are not in the list.
	 *
	 * @param string       $connection_id Connection id.
	 * @param list<string> $keep          Page ids to keep.
	 * @return list<string> Page ids deleted.
	 */
	public static function delete_except( string $connection_id, array $keep ): array {
		$gone = array_values( array_diff( array_keys( self::hashes( $connection_id ) ), $keep ) );

		self::delete_pages( $connection_id, $gone );

		return $gone;
	}

	/**
	 * Delete some pages of a connection.
	 *
	 * @param string       $connection_id Connection id.
	 * @param list<string> $page_ids      Page ids.
	 */
	public static function delete_pages( string $connection_id, array $page_ids ): void {
		global $wpdb;

		foreach ( $page_ids as $page_id ) {
			$wpdb->delete( self::table(), array( 'connection_id' => $connection_id, 'page_id' => $page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Delete every page of a connection.
	 *
	 * @param string $connection_id Connection id.
	 */
	public static function clear( string $connection_id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'connection_id' => $connection_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One stored page, by id or slug.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $page          Page id or slug.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $connection_id, string $page ): ?array {
		global $wpdb;

		$table  = self::table();
		$column = str_starts_with( $page, 'pg_' ) ? 'page_id' : 'slug';
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_id = %s AND {$column} = %s", $connection_id, $page ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * A connection's pages without their HTML, in feed order.
	 *
	 * @param string $connection_id Connection id.
	 * @return list<array<string,mixed>>
	 */
	public static function index( string $connection_id ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, slug, title, description, parents, sort_order, hash, fetched_at, (html <> '') AS has_html FROM {$table} WHERE connection_id = %s ORDER BY sort_order, id", $connection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/**
	 * Count a connection's stored pages.
	 *
	 * @param string $connection_id Connection id.
	 */
	public static function count( string $connection_id ): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE connection_id = %s AND html <> ''", $connection_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private static function decode( array $row ): array {
		foreach ( array( 'parents', 'headings' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$decoded        = json_decode( (string) $row[ $column ], true );
				$row[ $column ] = is_array( $decoded ) ? $decoded : array();
			}
		}

		return $row;
	}
}
