<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Feed\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase {

	private const TOKEN = 'zff_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

	public function test_accepts_a_feed_address(): void {
		$this->assertSame( 'https://zfolio.zuuid.cc/feed/v1/' . self::TOKEN, Address::normalize( ' https://ZFolio.Zuuid.cc/feed/v1/' . self::TOKEN . '/ ' ) );
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejects_what_is_not_a_feed_address( string $address ): void {
		$this->assertNull( Address::normalize( $address ) );
	}

	public static function rejected(): array {
		return array(
			'http'      => array( 'http://zfolio.zuuid.cc/feed/v1/' . self::TOKEN ),
			'ip'        => array( 'https://192.168.1.10/feed/v1/' . self::TOKEN ),
			'port'      => array( 'https://zfolio.zuuid.cc:8443/feed/v1/' . self::TOKEN ),
			'user'      => array( 'https://u:p@zfolio.zuuid.cc/feed/v1/' . self::TOKEN ),
			'query'     => array( 'https://zfolio.zuuid.cc/feed/v1/' . self::TOKEN . '?x=1' ),
			'v2'        => array( 'https://zfolio.zuuid.cc/feed/v2/' . self::TOKEN ),
			'page path' => array( 'https://zfolio.zuuid.cc/feed/v1/' . self::TOKEN . '/pages/x' ),
			'localhost' => array( 'https://localhost/feed/v1/' . self::TOKEN ),
			'empty'     => array( '' ),
			'other'     => array( 'https://feeds.example.com/feed/v1/' . self::TOKEN ),
			'lookalike' => array( 'https://zfolio.zuuid.cc.evil.com/feed/v1/' . self::TOKEN ),
			'sub'       => array( 'https://x.zfolio.zuuid.cc/feed/v1/' . self::TOKEN ),
			'dot'       => array( 'https://zfolio.zuuid.cc./feed/v1/' . self::TOKEN ),
			'internal'  => array( 'https://metadata.google.internal/feed/v1/' . self::TOKEN ),
		);
	}

	public function test_a_filter_can_allow_another_host(): void {
		$allow = static fn (): array => array( 'zfolio.zuuid.cc', 'feeds.example.com' );
		add_filter( 'bristlecone_zfolio_feed_hosts', $allow );

		try {
			$this->assertSame( 'https://feeds.example.com/feed/v1/' . self::TOKEN, Address::normalize( 'https://feeds.example.com/feed/v1/' . self::TOKEN ) );
		} finally {
			remove_filter( 'bristlecone_zfolio_feed_hosts', $allow );
		}
	}

	public function test_mask_hides_the_token(): void {
		$masked = Address::mask( 'https://zfolio.zuuid.cc/feed/v1/' . self::TOKEN );

		$this->assertStringStartsWith( 'https://zfolio.zuuid.cc/feed/v1/zff_', $masked );
		$this->assertStringEndsWith( 'AAAA', $masked );
		$this->assertStringNotContainsString( self::TOKEN, $masked );
	}
}
