<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Feed\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase {

	private function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/' . $name ), true );
	}

	public function test_fixture_manifest_is_valid(): void {
		$m = Validator::manifest( $this->fixture( 'manifest.json' ) );

		$this->assertNotNull( $m );
		$this->assertSame( 12, $m['revision'] );
		$this->assertCount( 3, $m['pages'] );
		$this->assertCount( 2, $m['folders'] );
		$this->assertSame( array( 'fl_mfrggzdfmztwq2lknnwg23tpob', 'fl_onxw2zjanbqxe5dfmfzgk3djmv' ), $m['pages']['pg_orsxg5banvqxi2dnn5zgk3tpoj']['parents'] );
	}

	public function test_fixture_page_is_valid(): void {
		$p = Validator::page( $this->fixture( 'page.json' ), 'zfd_Q2hhbmdlTWVJbkZpeHR1MA' );

		$this->assertNotNull( $p );
		$this->assertCount( 2, $p['headings'] );
	}

	public function test_page_from_another_feed_is_refused(): void {
		$this->assertNull( Validator::page( $this->fixture( 'page.json' ), 'zfd_AAAAAAAAAAAAAAAAAAAAAA' ) );
	}

	public function test_unknown_fields_are_ignored(): void {
		$data            = $this->fixture( 'manifest.json' );
		$data['newKey']  = array( 'x' => 1 );
		$data['pages'][0]['shiny'] = true;

		$this->assertNotNull( Validator::manifest( $data ) );
	}

	public function test_bad_manifests_are_refused(): void {
		$data               = $this->fixture( 'manifest.json' );
		$data['apiVersion'] = 2;
		$this->assertNull( Validator::manifest( $data ) );

		$data                     = $this->fixture( 'manifest.json' );
		$data['pages'][1]['slug'] = 'Has/Slash';
		$this->assertNull( Validator::manifest( $data ) );

		$data                     = $this->fixture( 'manifest.json' );
		$data['pages'][1]['slug'] = $data['pages'][0]['slug'];
		$this->assertNull( Validator::manifest( $data ) );

		$data                 = $this->fixture( 'manifest.json' );
		$data['revision']     = '12';
		$this->assertNull( Validator::manifest( $data ) );
	}

	public function test_schema_caps_are_enforced(): void {
		$cases = array(
			'revision 0'       => static function ( array &$m ): void { $m['revision'] = 0; },
			'allowlist -3'     => static function ( array &$m ): void { $m['allowlistVersion'] = -3; },
			'no allowlist'     => static function ( array &$m ): void { unset( $m['allowlistVersion'] ); },
			'long title'       => static function ( array &$m ): void { $m['title'] = str_repeat( 'x', 1001 ); },
			'long page title'  => static function ( array &$m ): void { $m['pages'][0]['title'] = str_repeat( 'x', 1001 ); },
			'negative order'   => static function ( array &$m ): void { $m['pages'][0]['order'] = -5; },
			'too many pages'   => static function ( array &$m ): void { $m['pages'] = array_fill( 0, 2001, $m['pages'][0] ); },
		);

		foreach ( $cases as $name => $change ) {
			$data = $this->fixture( 'manifest.json' );
			$change( $data );
			$this->assertNull( Validator::manifest( $data ), $name );
		}

		$data          = $this->fixture( 'manifest.json' );
		$data['title'] = str_repeat( 'é', 1000 );
		$this->assertNotNull( Validator::manifest( $data ), '1,000 characters, not bytes' );
	}

	public function test_heading_ids_need_the_prefix(): void {
		$data             = $this->fixture( 'page.json' );
		$data['headings'] = array(
			array( 'level' => 2, 'text' => 'Fine', 'id' => 'user-content-fine' ),
			array( 'level' => 2, 'text' => 'Bad', 'id' => 'not-prefixed<script>' ),
			array( 'level' => 2, 'text' => 'Bad too', 'id' => 'user-content-"><x' ),
		);

		$page = Validator::page( $data, 'zfd_Q2hhbmdlTWVJbkZpeHR1MA' );

		$this->assertSame( array( 'user-content-fine' ), array_column( $page['headings'], 'id' ) );
	}
}
