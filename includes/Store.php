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

	public const DB_VERSION         = '2';
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
			sanitizer smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_page (connection_id,page_id),
			KEY connection_slug (connection_id,slug(191))
			) {$charset};"
		);

		// Record the version only once the table is really there, so a failed
		// create is tried again on the next request.
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}
	}

	/**
	 * Drop the table and its version (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Stored hashes of a connection's pages, keyed by page id.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,string>
	 */
	public static function hashes( string $connection_id ): array {
		return array_map( static fn ( array $row ): string => $row['hash'], self::manifest_rows( $connection_id ) );
	}

	/**
	 * What the manifest decides about each stored page, without any HTML:
	 * its hash ('' when never fetched) and its manifest fields.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,array{hash:string,slug:string,title:string,description:string,parents:string,order:int}>
	 */
	public static function manifest_rows( string $connection_id ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, CASE WHEN html = '' THEN '' ELSE hash END AS hash, slug, title, description, parents, sort_order FROM %i WHERE connection_id = %s", $table, $connection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['page_id'] ] = array(
				'hash'        => (string) $row['hash'],
				'slug'        => (string) $row['slug'],
				'title'       => (string) $row['title'],
				'description' => (string) $row['description'],
				'parents'     => (string) $row['parents'],
				'order'       => (int) $row['sort_order'],
			);
		}

		return $out;
	}

	/**
	 * Whether a manifest page's fields differ from the stored row.
	 *
	 * @param array<string,mixed>      $meta   Validated manifest page.
	 * @param array<string,mixed>|null $stored Stored row from manifest_rows().
	 */
	public static function meta_changed( array $meta, ?array $stored ): bool {
		return null === $stored
			|| $stored['slug'] !== $meta['slug']
			|| $stored['title'] !== $meta['title']
			|| $stored['description'] !== $meta['description']
			|| (string) wp_json_encode( $meta['parents'] ) !== $stored['parents']
			|| $stored['order'] !== (int) $meta['order'];
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
				"INSERT INTO %i (connection_id, page_id, slug, title, description, parents, sort_order, hash, html, headings) VALUES (%s, %s, %s, %s, %s, %s, %d, '', '', '[]') ON DUPLICATE KEY UPDATE slug = VALUES(slug), title = VALUES(title), description = VALUES(description), parents = VALUES(parents), sort_order = VALUES(sort_order)",
				$table,
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
	 * @return bool Whether the row was written.
	 */
	public static function save_content( string $connection_id, array $page, int $allowlist ): bool {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
				'sanitizer'         => Content\Sanitizer::GENERATION,
			),
			array(
				'connection_id' => $connection_id,
				'page_id'       => $page['id'],
			)
		);

		// 0 rows changed is fine (same content again); false is a database error.
		return false !== $result;
	}

	/**
	 * Save HTML cleaned again by a newer sanitizer.
	 *
	 * @param string $connection_id Connection id.
	 * @param string $page_id       Page id.
	 * @param string $html          Clean HTML.
	 */
	public static function save_recleaned( string $connection_id, string $page_id, string $html ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			self::table(),
			array(
				'html'      => $html,
				'sanitizer' => Content\Sanitizer::GENERATION,
			),
			array(
				'connection_id' => $connection_id,
				'page_id'       => $page_id,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A connection's slugs mapped to page ids, for resolving links.
	 *
	 * @param string $connection_id Connection id.
	 * @return array<string,string>
	 */
	public static function slugs( string $connection_id ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, slug FROM %i WHERE connection_id = %s", $table, $connection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['slug'] ] = (string) $row['page_id'];
		}

		return $out;
	}

	/**
	 * Delete pages of a connection that are not in the list.
	 *
	 * @param string            $connection_id Connection id.
	 * @param list<string>      $keep          Page ids to keep.
	 * @param list<string>|null $stored        Stored page ids, when already known.
	 * @return list<string> Page ids deleted.
	 */
	public static function delete_except( string $connection_id, array $keep, ?array $stored = null ): array {
		$gone = array_values( array_diff( $stored ?? array_keys( self::hashes( $connection_id ) ), $keep ) );

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
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				self::table(),
				array(
					'connection_id' => $connection_id,
					'page_id'       => $page_id,
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE connection_id = %s AND %i = %s", $table, $connection_id, $column, $page ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, slug, title, description, parents, sort_order, hash, fetched_at, (html <> '') AS has_html FROM %i WHERE connection_id = %s ORDER BY sort_order, id", $table, $connection_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE connection_id = %s AND html <> ''", $table, $connection_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
