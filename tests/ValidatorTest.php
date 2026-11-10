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
}
