<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Feed\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase {

	private const TOKEN = 'zff_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

	public function test_accepts_a_feed_address(): void {
		$this->assertSame( 'https://feeds.example.com/feed/v1/' . self::TOKEN, Address::normalize( ' https://Feeds.Example.com/feed/v1/' . self::TOKEN . '/ ' ) );
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejects_what_is_not_a_feed_address( string $address ): void {
		$this->assertNull( Address::normalize( $address ) );
	}

	public static function rejected(): array {
		return array(
			'http'      => array( 'http://feeds.example.com/feed/v1/' . self::TOKEN ),
			'ip'        => array( 'https://192.168.1.10/feed/v1/' . self::TOKEN ),
			'port'      => array( 'https://feeds.example.com:8443/feed/v1/' . self::TOKEN ),
			'user'      => array( 'https://u:p@feeds.example.com/feed/v1/' . self::TOKEN ),
			'query'     => array( 'https://feeds.example.com/feed/v1/' . self::TOKEN . '?x=1' ),
			'v2'        => array( 'https://feeds.example.com/feed/v2/' . self::TOKEN ),
			'page path' => array( 'https://feeds.example.com/feed/v1/' . self::TOKEN . '/pages/x' ),
			'localhost' => array( 'https://localhost/feed/v1/' . self::TOKEN ),
			'empty'     => array( '' ),
		);
	}

	public function test_mask_hides_the_token(): void {
		$masked = Address::mask( 'https://feeds.example.com/feed/v1/' . self::TOKEN );

		$this->assertStringStartsWith( 'https://feeds.example.com/feed/v1/zff_', $masked );
		$this->assertStringEndsWith( 'AAAA', $masked );
		$this->assertStringNotContainsString( self::TOKEN, $masked );
	}
}
