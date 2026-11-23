<?php
/**
 * Minimal stand-ins for WordPress functions the tested code calls.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

// phpcs:disable

function __( $text, $domain = 'default' ) { return $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function get_bloginfo( $show = '' ) { return 'version' === $show ? '6.4.0' : ''; }
function wp_allowed_protocols() { return array( 'http', 'https', 'mailto' ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['zf_test_options'] ?? array() ) ? $GLOBALS['zf_test_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['zf_test_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['zf_test_options'][ $name ] ); return true; }
function wp_doing_cron() { return false; }
function spawn_cron() { $GLOBALS['zf_test_spawned'] = ( $GLOBALS['zf_test_spawned'] ?? 0 ) + 1; }
function wp_next_scheduled( $hook, $args = array() ) {
	$times = array_keys( array_filter( $GLOBALS['zf_test_cron'] ?? array(), static fn ( $event ) => $event[0] === $hook && $event[1] === $args ) );
	return $times ? min( array_map( static fn ( $key ) => (int) explode( '|', $key )[0], $times ) ) : false;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { $GLOBALS['zf_test_cron'][ $timestamp . '|' . $hook . '|' . serialize( $args ) ] = array( $hook, $args ); return true; }
function wp_unschedule_event( $timestamp, $hook, $args = array() ) { unset( $GLOBALS['zf_test_cron'][ $timestamp . '|' . $hook . '|' . serialize( $args ) ] ); return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['zf_test_cron'] = array_filter( $GLOBALS['zf_test_cron'] ?? array(), static fn ( $event ) => ! ( $event[0] === $hook && $event[1] === $args ) );
	return 0;
}
function is_wp_error( $thing ) { return false; }
function apply_shortcodes( $content ) { return $content; }
function mbstring_binary_safe_encoding( $reset = false ) {}
function reset_mbstring_encoding() {}
