<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Feed\Validator;
use Bristlecone\Zfolio\Mirror\Tree;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase {

	private function manifest(): array {
		return Validator::manifest( json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/manifest.json' ), true ) );
	}

	private function rows( array $manifest ): array {
		return array_map(
			static fn ( array $p ): array => array( 'page_id' => $p['id'], 'slug' => $p['slug'], 'title' => $p['title'], 'description' => $p['description'], 'parents' => $p['parents'], 'sort_order' => $p['order'] ),
			array_values( $manifest['pages'] )
		);
	}

	public function test_fixture_folder_tree(): void {
		$m     = $this->manifest();
		$nodes = Tree::build( 'folder', 'Product Guide', $m['title'], $m['folders'], $this->rows( $m ) );

		$this->assertSame( 'product-guide', $nodes['root']['slug'] );
		$this->assertSame( '', $nodes['root']['parent'] );
		$this->assertSame( 'root', $nodes['fl_mfrggzdfmztwq2lknnwg23tpob']['parent'] );
		$this->assertSame( 'setup', $nodes['fl_mfrggzdfmztwq2lknnwg23tpob']['slug'] );
		$this->assertSame( 'fl_mfrggzdfmztwq2lknnwg23tpob', $nodes['fl_onxw2zjanbqxe5dfmfzgk3djmv']['parent'] );
		$this->assertSame( 'fl_onxw2zjanbqxe5dfmfzgk3djmv', $nodes['pg_orsxg5banvqxi2dnn5zgk3tpoj']['parent'] );
		$this->assertSame( 'tuning', $nodes['pg_orsxg5banvqxi2dnn5zgk3tpoj']['slug'] );
		$this->assertSame( 'pg_orsxg5banvqxi2dnn5zgk3tpoj', $nodes['pg_orsxg5banvqxi2dnn5zgk3tpoj']['page'] );
		$this->assertSame( '', $nodes['fl_onxw2zjanbqxe5dfmfzgk3djmv']['page'] );
	}

	public function test_parents_come_before_children(): void {
		$m       = $this->manifest();
		$folders = array_reverse( $m['folders'], true );
		$keys    = array_keys( Tree::build( 'folder', 'x', '', $folders, array_reverse( $this->rows( $m ) ) ) );
		$seen    = array();

		foreach ( Tree::build( 'folder', 'x', '', $folders, $this->rows( $m ) ) as $key => $node ) {
			$this->assertTrue( '' === $node['parent'] || isset( $seen[ $node['parent'] ] ), $key );
			$seen[ $key ] = true;
		}

		$this->assertSame( 'root', $keys[0] );
	}

	public function test_sibling_slugs_are_unique(): void {
		$folders = array( 'fl_aaaaaaaaaaaaaaaaaaaaaaaaaa' => array( 'title' => 'Guide', 'parents' => array(), 'order' => 0 ) );
		$pages   = array(
			array( 'page_id' => 'pg_aaaaaaaaaaaaaaaaaaaaaaaaaa', 'slug' => 'guide', 'title' => 'Guide', 'description' => '', 'parents' => array(), 'sort_order' => 1 ),
			array( 'page_id' => 'pg_bbbbbbbbbbbbbbbbbbbbbbbbbb', 'slug' => 'intro', 'title' => 'Intro', 'description' => '', 'parents' => array( 'fl_aaaaaaaaaaaaaaaaaaaaaaaaaa' ), 'sort_order' => 0 ),
		);
		$nodes   = Tree::build( 'folder', '', 'My Docs', $folders, $pages );

		$this->assertSame( 'zfolio', $nodes['root']['slug'] );
		$this->assertSame( 'My Docs', $nodes['root']['title'] );
		$this->assertSame( 'guide', $nodes['fl_aaaaaaaaaaaaaaaaaaaaaaaaaa']['slug'] );
		$this->assertSame( 'guide-2', $nodes['pg_aaaaaaaaaaaaaaaaaaaaaaaaaa']['slug'] );
	}

	public function test_orphans_are_skipped(): void {
		$pages = array( array( 'page_id' => 'pg_aaaaaaaaaaaaaaaaaaaaaaaaaa', 'slug' => 'a', 'title' => 'A', 'description' => '', 'parents' => array( 'fl_zzzzzzzzzzzzzzzzzzzzzzzzzz' ), 'sort_order' => 0 ) );

		$this->assertSame( array( 'root' ), array_keys( Tree::build( 'folder', 'x', '', array(), $pages ) ) );
	}

	public function test_document_feed_is_one_page(): void {
		$pages = array( array( 'page_id' => 'pg_aaaaaaaaaaaaaaaaaaaaaaaaaa', 'slug' => 'readme', 'title' => 'Read Me', 'description' => 'About', 'parents' => array(), 'sort_order' => 0 ) );
		$nodes = Tree::build( 'document', 'handbook', 'Read Me', array(), $pages );

		$this->assertSame( array( 'root' ), array_keys( $nodes ) );
		$this->assertSame( 'handbook', $nodes['root']['slug'] );
		$this->assertSame( 'pg_aaaaaaaaaaaaaaaaaaaaaaaaaa', $nodes['root']['page'] );
		$this->assertSame( 'About', $nodes['root']['excerpt'] );
	}
}
