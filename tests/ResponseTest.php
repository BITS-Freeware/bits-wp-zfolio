<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Feed\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase {

	private function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/' . $name );
	}

	public function test_ok_keeps_etag_and_data(): void {
		$r = Response::from_parts( 200, array( 'ETag' => '"12"' ), $this->fixture( 'manifest.json' ) );

		$this->assertSame( Response::OK, $r->outcome );
		$this->assertSame( '"12"', $r->etag );
		$this->assertSame( 12, $r->data['revision'] );
	}

	public function test_not_modified(): void {
		$this->assertSame( Response::NOT_MODIFIED, Response::from_parts( 304, array(), '' )->outcome );
	}

	public function test_404_codes_are_told_apart(): void {
		$this->assertSame( Response::PAUSED, Response::from_parts( 404, array(), $this->fixture( 'error-paused.json' ) )->outcome );
		$this->assertSame( Response::REVOKED, Response::from_parts( 404, array(), $this->fixture( 'error-revoked.json' ) )->outcome );
		$this->assertSame( Response::NOT_FOUND, Response::from_parts( 404, array(), $this->fixture( 'error-not_found.json' ) )->outcome );
		$this->assertSame( Response::NOT_FOUND, Response::from_parts( 404, array(), 'not json' )->outcome );
	}

	public function test_gone_names_the_current_version(): void {
		$r = Response::from_parts( 410, array(), $this->fixture( 'gone.json' ) );

		$this->assertSame( Response::GONE, $r->outcome );
		$this->assertSame( 2, $r->current );
	}

	public function test_rate_limit_reads_retry_after(): void {
		$r = Response::from_parts( 429, array( 'retry-after' => '120' ), 'slow down' );

		$this->assertSame( Response::RATE_LIMITED, $r->outcome );
		$this->assertSame( 120, $r->retry_after );
		$this->assertSame( 30, Response::retry_after( '1' ) );
		$this->assertSame( 3600, Response::retry_after( '99999' ) );
	}

	public function test_errors_and_bad_json_never_count_as_content(): void {
		$this->assertSame( Response::FAILED, Response::from_parts( 503, array(), '' )->outcome );
		$this->assertSame( Response::FAILED, Response::from_parts( 200, array(), '<html>' )->outcome );
		$this->assertSame( Response::FAILED, Response::from_parts( 0, array(), '', 'timeout' )->outcome );
	}

	public function test_deprecation_and_sunset_are_kept(): void {
		$r = Response::from_parts( 304, array( 'Deprecation' => '@1798761600', 'Sunset' => 'Fri, 01 Oct 2027 00:00:00 GMT' ), '' );

		$this->assertSame( '@1798761600', $r->deprecation );
		$this->assertSame( 'Fri, 01 Oct 2027 00:00:00 GMT', $r->sunset );
	}
}
