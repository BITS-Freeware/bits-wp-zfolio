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
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.

	delete_option( 'bristlecone_zfolio_connections' );
	delete_option( 'bristlecone_zfolio_state' );
	delete_option( 'bristlecone_zfolio_db_version' );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'bristlecone_zfolio_lock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'bristlecone_zfolio_state_', '_transient_bristlecone_zfolio_evt_', '_transient_timeout_bristlecone_zfolio_evt_' ) as $bristlecone_zfolio_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $bristlecone_zfolio_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	delete_post_meta_by_key( '_bristlecone_zfolio_ref' );

	$bristlecone_zfolio_posts = get_posts(
		array(
			'post_type'      => 'bristlecone_zfolio',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $bristlecone_zfolio_posts as $bristlecone_zfolio_post ) {
		wp_delete_post( (int) $bristlecone_zfolio_post, true );
	}

	foreach ( array( 'bristlecone_zfolio_mirror_base', 'bristlecone_zfolio_mirror_moved', 'bristlecone_zfolio_flush_rewrite' ) as $bristlecone_zfolio_option ) {
		delete_option( $bristlecone_zfolio_option );
	}
	wp_unschedule_hook( 'bristlecone_zfolio_poll' );
	wp_unschedule_hook( 'bristlecone_zfolio_mirror' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $bristlecone_zfolio_site ) {
		switch_to_blog( (int) $bristlecone_zfolio_site );
		bristlecone_zfolio_uninstall_site();
		restore_current_blog();
	}
} else {
	bristlecone_zfolio_uninstall_site();
}
