<?php
/**
 * Sanitizes page HTML with the bundled feed allowlist.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Content;

use WP_HTML_Tag_Processor;

/**
 * Two passes: wp_kses() with the allowlist's elements and attributes, then a
 * tag-processor pass for the allowlist's value rules (values and patterns),
 * which wp_kses() cannot express.
 *
 * The allowlist is fixed per allowlistVersion and ships with the plugin. It is
 * never fetched, so nothing on the feed side can widen what WordPress accepts.
 */
final class Sanitizer {

	/**
	 * Highest allowlistVersion this plugin ships.
	 */
	public const ALLOWLIST_VERSION = 1;

	/**
	 * Parsed allowlist.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $allowlist = null;

	/**
	 * Sanitize page HTML.
	 *
	 * @param string $html HTML from the feed.
	 */
	public static function clean( string $html ): string {
		$list = self::allowlist();

		$html = wp_kses( $html, self::kses_elements( $list ), self::protocols( $list ) );

		return self::enforce_rules( $html, $list );
	}

	/**
	 * Replace MathML with its TeX annotation, for sites that don't show MathML.
	 *
	 * @param string $html Sanitized HTML.
	 */
	public static function strip_math( string $html ): string {
		return (string) preg_replace_callback(
			'#<math\b[^>]*>(.*?)</math>#si',
			static function ( array $match ): string {
				if ( preg_match( '#<annotation\b[^>]*>(.*?)</annotation>#si', $match[1], $tex ) ) {
					// The annotation was text in sanitized HTML, so it is already escaped.
					return '<code class="zfolio-tex">' . trim( wp_strip_all_tags( $tex[1] ) ) . '</code>';
				}

				return '';
			},
			$html
		);
	}

	/**
	 * The bundled allowlist.
	 *
	 * @return array<string,mixed>
	 */
	public static function allowlist(): array {
		if ( null === self::$allowlist ) {
			$path = dirname( __DIR__, 2 ) . '/data/kses-allowlist-v1.json';
			$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.

			self::$allowlist = is_array( $data ) ? $data : array(
				'elements'         => array(),
				'globalAttributes' => array(),
				'protocols'        => array(),
			);
		}

		return self::$allowlist;
	}

	/**
	 * Element => attribute => true, as wp_kses() wants it.
	 *
	 * @param array<string,mixed> $list Allowlist.
	 * @return array<string,array<string,bool>>
	 */
	private static function kses_elements( array $list ): array {
		$global = array_fill_keys( array_keys( (array) ( $list['globalAttributes'] ?? array() ) ), true );
		$out    = array();

		foreach ( (array) ( $list['elements'] ?? array() ) as $element => $attributes ) {
			$out[ strtolower( (string) $element ) ] = array_fill_keys( array_map( 'strtolower', array_keys( (array) $attributes ) ), true ) + $global;
		}

		return $out;
	}

	/**
	 * Every protocol the allowlist names, for wp_kses().
	 *
	 * @param array<string,mixed> $list Allowlist.
	 * @return list<string>
	 */
	private static function protocols( array $list ): array {
		$all = array();

		foreach ( (array) ( $list['protocols'] ?? array() ) as $protocols ) {
			$all = array_merge( $all, (array) $protocols );
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * Apply value rules, the id prefix, required attributes, and srcset.
	 *
	 * @param string              $html Kses-clean HTML.
	 * @param array<string,mixed> $list Allowlist.
	 */
	private static function enforce_rules( string $html, array $list ): string {
		$elements = (array) ( $list['elements'] ?? array() );
		$global   = (array) ( $list['globalAttributes'] ?? array() );
		$required = (array) ( $list['required'] ?? array() );
		$tags     = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {
			if ( $tags->is_tag_closer() ) {
				continue;
			}

			$tag   = strtolower( (string) $tags->get_tag() );
			$rules = (array) ( $elements[ $tag ] ?? array() );
			$names = $tags->get_attribute_names_with_prefix( '' ) ?? array();

			foreach ( $names as $name ) {
				$rule  = $rules[ $name ] ?? $global[ $name ] ?? false;
				$value = $tags->get_attribute( $name );

				if ( false === $rule ) {
					$tags->remove_attribute( $name );
					continue;
				}

				if ( ( 'id' === $name || 'name' === $name ) && ( ! is_string( $value ) || ! str_starts_with( $value, 'user-content-' ) ) ) {
					$tags->remove_attribute( $name );
					continue;
				}

				if ( 'srcset' === $name ) {
					$kept = self::clean_srcset( is_string( $value ) ? $value : '' );

					if ( '' === $kept ) {
						$tags->remove_attribute( $name );
					} else {
						$tags->set_attribute( $name, $kept );
					}
					continue;
				}

				if ( ! is_array( $rule ) ) {
					continue;
				}

				if ( 'class' === $name ) {
					$tokens = preg_split( '/\s+/', is_string( $value ) ? trim( $value ) : '', -1, PREG_SPLIT_NO_EMPTY ) ?: array();
					$kept   = array_values( array_filter( $tokens, static fn ( string $token ): bool => self::matches( $token, $rule ) ) );

					if ( array() === $kept ) {
						$tags->remove_attribute( 'class' );
					} elseif ( $kept !== $tokens ) {
						$tags->set_attribute( 'class', implode( ' ', $kept ) );
					}
					continue;
				}

				if ( ! is_string( $value ) || ! self::matches( $value, $rule ) ) {
					$tags->remove_attribute( $name );
				}
			}

			foreach ( (array) ( $required[ $tag ] ?? array() ) as $name => $value ) {
				$tags->set_attribute( (string) $name, $value );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Whether a value passes a values/patterns rule.
	 *
	 * @param string              $value Value.
	 * @param array<string,mixed> $rule  Rule.
	 */
	private static function matches( string $value, array $rule ): bool {
		if ( in_array( $value, (array) ( $rule['values'] ?? array() ), true ) ) {
			return true;
		}

		foreach ( (array) ( $rule['patterns'] ?? array() ) as $pattern ) {
			$regex = '~' . str_replace( '~', '\\~', (string) $pattern ) . '~u';

			if ( 1 === @preg_match( $regex, $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern simply fails closed.
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep only https candidates in a srcset.
	 *
	 * @param string $srcset Raw srcset.
	 */
	private static function clean_srcset( string $srcset ): string {
		$kept = array();

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			$parts     = preg_split( '/\s+/', $candidate ) ?: array();
			$url       = $parts[0] ?? '';

			if ( str_starts_with( $url, 'https://' ) && false === strpbrk( $url, '"\'<>' ) ) {
				$kept[] = $candidate;
			}
		}

		return implode( ', ', $kept );
	}
}
