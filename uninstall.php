<?php
/**
 * Uninstall zFolio for WordPress: remove its table, options, events and post
 * meta from every site.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything from the current site.
 */
function bristlecone_zfolio_uninstall_site(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'bristlecone_zfolio_pages';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.

	delete_option( 'bristlecone_zfolio_connections' );
	delete_option( 'bristlecone_zfolio_state' );
	delete_option( 'bristlecone_zfolio_db_version' );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'bristlecone_zfolio_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( '_transient_bristlecone_zfolio_evt_', '_transient_timeout_bristlecone_zfolio_evt_' ) as $bristlecone_zfolio_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $bristlecone_zfolio_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	delete_post_meta_by_key( '_bristlecone_zfolio_ref' );
	wp_unschedule_hook( 'bristlecone_zfolio_poll' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bristlecone_zfolio_site ) {
		switch_to_blog( (int) $bristlecone_zfolio_site );
		bristlecone_zfolio_uninstall_site();
		restore_current_blog();
	}
} else {
	bristlecone_zfolio_uninstall_site();
}
