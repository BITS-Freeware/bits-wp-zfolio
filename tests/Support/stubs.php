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
function get_option( $name, $default = false ) { return $default; }
function is_wp_error( $thing ) { return false; }
function apply_shortcodes( $content ) { return $content; }
