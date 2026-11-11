<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Webhook\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase {

	private function events(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/docs/zfolio-feed/v1/fixtures/events.json' ), true );
	}

	public function test_every_fixture_parses(): void {
		foreach ( $this->events() as $event ) {
			$parsed = Event::parse( (string) json_encode( $event ) );

			$this->assertNotNull( $parsed, $event['type'] );
			$this->assertSame( $event['id'], $parsed['id'] );
			$this->assertSame( $event['type'], $parsed['type'] );
		}
	}

	public function test_fields_the_plugin_uses(): void {
		$e = array_map( static fn ( array $event ) => Event::parse( (string) json_encode( $event ) ), $this->events() );

		$this->assertSame( 12, $e[0]['revision'] );
		$this->assertSame( array(), $e[0]['pages'] );
		$this->assertTrue( $e[1]['truncated'] );
		$this->assertSame( array( 'pg_orsxg5banvqxi2dnn5zgk3tpoj' ), $e[2]['pages'] );
		$this->assertSame( 'address', $e[5]['what'] );
		$this->assertSame( strtotime( '2026-12-17T08:00:00.000Z' ), $e[5]['until'] );
		$this->assertSame( 'secret', $e[6]['what'] );
		$this->assertNull( $e[7]['revision'] );
	}

	public function test_unknown_fields_and_types_pass(): void {
		$event                 = $this->events()[0];
		$event['extra']        = array( 'x' => 1 );
		$event['data']['more'] = true;
		$this->assertNotNull( Event::parse( (string) json_encode( $event ) ) );

		$event         = $this->events()[8];
		$event['type'] = 'feed.something_new';
		$this->assertSame( 'feed.something_new', Event::parse( (string) json_encode( $event ) )['type'] );
	}

	public function test_bad_events_are_refused(): void {
		$cases = array(
			array( 'apiVersion', 2 ),
			array( 'id', 'evt_short' ),
			array( 'feedId', 'zfd_nope' ),
			array( 'createdAt', 'yesterday-ish' ),
			array( 'data', 'x' ),
		);

		foreach ( $cases as $case ) {
			$event             = $this->events()[0];
			$event[ $case[0] ] = $case[1];
			$this->assertNull( Event::parse( (string) json_encode( $event ) ), $case[0] );
		}

		$event                     = $this->events()[0];
		$event['data']['revision'] = '12';
		$this->assertNull( Event::parse( (string) json_encode( $event ) ) );

		$event                    = $this->events()[0];
		$event['data']['removed'] = array( '../etc' );
		$this->assertNull( Event::parse( (string) json_encode( $event ) ) );

		$event = $this->events()[2];
		unset( $event['data']['pages'] );
		$this->assertNull( Event::parse( (string) json_encode( $event ) ) );

		$event                 = $this->events()[5];
		$event['data']['what'] = 'everything';
		$this->assertNull( Event::parse( (string) json_encode( $event ) ) );

		$this->assertNull( Event::parse( '' ) );
		$this->assertNull( Event::parse( 'not json' ) );
		$this->assertNull( Event::parse( str_repeat( ' ', Event::MAX_BYTES + 1 ) ) );
	}
}
