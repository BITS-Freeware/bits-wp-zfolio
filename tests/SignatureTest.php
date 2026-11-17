<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Webhook\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	private function vectors(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/test-vectors/signatures.json' ), true );
	}

	public function test_contract_vectors_sign_and_verify(): void {
		foreach ( $this->vectors()['vectors'] as $vector ) {
			$this->assertSame( $vector['signature'], Signature::sign( $vector['secret'], $vector['t'], $vector['body'] ), $vector['name'] );
			$this->assertSame( Signature::OK, Signature::verify( $vector['header'], $vector['body'], $vector['secret'], $vector['t'] + 10 ), $vector['name'] );
		}
	}

	public function test_rolled_secret_accepts_either_secret(): void {
		$rolled = $this->vectors()['rolled'];

		$this->assertSame( Signature::OK, Signature::verify( $rolled['header'], $rolled['body'], $rolled['secret'], $rolled['t'] ) );
		$this->assertSame( Signature::OK, Signature::verify( $rolled['header'], $rolled['body'], $rolled['previousSecret'], $rolled['t'] ) );
		$this->assertSame( Signature::MISMATCH, Signature::verify( $rolled['header'], $rolled['body'], 'zfwh_' . str_repeat( 'x', 43 ), $rolled['t'] ) );
	}

	public function test_timestamp_window(): void {
		$v = $this->vectors()['vectors'][0];

		$this->assertSame( Signature::OK, Signature::verify( $v['header'], $v['body'], $v['secret'], $v['t'] + 300 ) );
		$this->assertSame( Signature::OK, Signature::verify( $v['header'], $v['body'], $v['secret'], $v['t'] - 300 ) );
		$this->assertSame( Signature::EXPIRED, Signature::verify( $v['header'], $v['body'], $v['secret'], $v['t'] + 301 ) );
		$this->assertSame( Signature::EXPIRED, Signature::verify( $v['header'], $v['body'], $v['secret'], $v['t'] - 301 ) );
	}

	public function test_tampering_is_refused(): void {
		$v = $this->vectors()['vectors'][1];

		$this->assertSame( Signature::MISMATCH, Signature::verify( $v['header'], $v['body'] . ' ', $v['secret'], $v['t'] ) );
		$this->assertSame( Signature::MISMATCH, Signature::verify( str_replace( 't=' . $v['t'], 't=' . ( $v['t'] + 1 ), $v['header'] ), $v['body'], $v['secret'], $v['t'] ) );
	}

	public function test_malformed_headers(): void {
		$v = $this->vectors()['vectors'][0];

		foreach ( array( '', 't=1794000000', 'v1=' . $v['signature'], 't=abc,v1=' . $v['signature'], 't=1794000000,v1=' . strtoupper( $v['signature'] ), 't=1794000000,v0=' . $v['signature'] ) as $header ) {
			$this->assertSame( Signature::MALFORMED, Signature::verify( $header, $v['body'], $v['secret'], $v['t'] ), $header );
		}

		$this->assertSame( Signature::MALFORMED, Signature::verify( $v['header'], $v['body'], '', $v['t'] ) );
		$this->assertSame( Signature::OK, Signature::verify( ' t=1794000000 , v1=' . $v['signature'] . ' ', $v['body'], $v['secret'], $v['t'] ) );
	}

	public function test_secret_shape_and_mask(): void {
		$secret = $this->vectors()['vectors'][0]['secret'];

		$this->assertTrue( Signature::valid_secret( $secret ) );
		$this->assertTrue( Signature::valid_secret( $this->vectors()['rolled']['secret'] ) );
		$this->assertFalse( Signature::valid_secret( substr( $secret, 0, -1 ) ) );
		$this->assertFalse( Signature::valid_secret( 'zff_' . substr( $secret, 5 ) . 'x' ) );
		$this->assertSame( 'zfwh_••••••••DEFG', Signature::mask( $secret ) );
		$this->assertStringNotContainsString( substr( $secret, 5, 20 ), Signature::mask( $secret ) );
	}
}
