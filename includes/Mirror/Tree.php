<?php
/**
 * The page tree a mirrored connection should have.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Mirror;

/**
 * Turns a feed's folders and pages into an ordered list of nodes, parents
 * before children, with a slug unique among its siblings.
 *
 * A folder feed becomes a root page (the feed), its folders below it, and
 * each page under its innermost folder. A one-page document feed becomes a
 * single root page that shows the document.
 */
final class Tree {

	public const ROOT = 'root';

	/**
	 * Build the nodes.
	 *
	 * @param string                           $kind      'folder' or 'document'.
	 * @param string                           $root_slug Slug of the root page.
	 * @param string                           $title     Feed title.
	 * @param array<string,array<string,mixed>> $folders   Folders by id: title, parents, order.
	 * @param array<int,array<string,mixed>>    $pages     Pages: page_id, slug, title, description, parents, sort_order.
	 * @return array<string,array{key:string,parent:string,slug:string,title:string,order:int,page:string,excerpt:string}>
	 */
	public static function build( string $kind, string $root_slug, string $title, array $folders, array $pages ): array {
		$root_slug = sanitize_title( $root_slug );
		$root_slug = '' !== $root_slug ? $root_slug : 'zfolio';

		if ( 'document' === $kind && 1 === count( $pages ) ) {
			$page = reset( $pages );

			return array(
				self::ROOT => self::node( self::ROOT, '', $root_slug, (string) $page['title'], 0, (string) $page['page_id'], (string) $page['description'] ),
			);
		}

		$nodes = array(
			self::ROOT => self::node( self::ROOT, '', $root_slug, '' !== $title ? $title : $root_slug, 0, '', '' ),
		);

		// Outermost folders first, so a parent always precedes its children.
		uasort(
			$folders,
			static fn ( array $a, array $b ): int => array( count( $a['parents'] ), $a['order'] ) <=> array( count( $b['parents'] ), $b['order'] )
		);

		$taken = array();

		foreach ( $folders as $id => $folder ) {
			$parents = (array) $folder['parents'];
			$parent  = $parents ? (string) end( $parents ) : self::ROOT;

			if ( ! isset( $nodes[ $parent ] ) ) {
				continue; // A folder whose parent is unknown is skipped, with its pages.
			}

			$name         = (string) $folder['title'];
			$nodes[ $id ]   = self::node( (string) $id, $parent, self::unique( $taken, $parent, sanitize_title( $name ), 'folder' ), $name, (int) $folder['order'], '', '' );
		}

		foreach ( $pages as $page ) {
			$parents = (array) $page['parents'];
			$parent  = $parents ? (string) end( $parents ) : self::ROOT;

			if ( ! isset( $nodes[ $parent ] ) ) {
				continue;
			}

			$id           = (string) $page['page_id'];
			$nodes[ $id ] = self::node( $id, $parent, self::unique( $taken, $parent, (string) $page['slug'], 'page' ), (string) $page['title'], (int) $page['sort_order'], $id, (string) $page['description'] );
		}

		return $nodes;
	}

	/**
	 * A slug not yet used under a parent.
	 *
	 * @param array<string,true> $taken  Used "parent|slug" pairs.
	 * @param string             $parent Parent key.
	 * @param string             $slug   Wanted slug.
	 * @param string             $empty  Fallback for an empty slug.
	 */
	private static function unique( array &$taken, string $parent, string $slug, string $empty ): string {
		$slug = '' !== $slug ? $slug : $empty;
		$try  = $slug;

		for ( $n = 2; isset( $taken[ $parent . '|' . $try ] ); $n++ ) {
			$try = $slug . '-' . $n;
		}

		$taken[ $parent . '|' . $try ] = true;

		return $try;
	}

	/**
	 * One node.
	 *
	 * @param string $key     Node key.
	 * @param string $parent  Parent key.
	 * @param string $slug    Slug.
	 * @param string $title   Title.
	 * @param int    $order   Sort order.
	 * @param string $page    Page id shown, or ''.
	 * @param string $excerpt Excerpt.
	 * @return array{key:string,parent:string,slug:string,title:string,order:int,page:string,excerpt:string}
	 */
	private static function node( string $key, string $parent, string $slug, string $title, int $order, string $page, string $excerpt ): array {
		return array(
			'key'     => $key,
			'parent'  => $parent,
			'slug'    => $slug,
			'title'   => $title,
			'order'   => $order,
			'page'    => $page,
			'excerpt' => $excerpt,
		);
	}
}
