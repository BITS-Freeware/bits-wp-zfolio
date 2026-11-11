<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Content\Links;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase {

	public function test_links_point_at_wordpress_addresses(): void {
		$html = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/page.json' ), true )['html'];
		$out  = Links::rewrite(
			$html,
			static fn ( string $slug ): ?string => 'welcome' === $slug ? 'https://site.example/guide/welcome/' : ( 'tuning' === $slug ? 'https://site.example/guide/tuning/' : null )
		);

		$this->assertStringContainsString( 'href="https://site.example/guide/welcome/"', $out );
		$this->assertStringContainsString( 'href="https://site.example/guide/tuning/#user-content-limits"', $out );
	}

	public function test_links_with_no_address_lose_their_href(): void {
		$out = Links::rewrite( '<a href="#" class="wiki-link" data-zfolio-slug="gone">Gone</a>', static fn (): ?string => null );

		$this->assertStringNotContainsString( 'href', $out );
		$this->assertStringContainsString( '>Gone</a>', $out );
	}
}
