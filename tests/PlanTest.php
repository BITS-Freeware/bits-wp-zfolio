<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Plan;
use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase {

	private function pages(): array {
		return array(
			'pg_a' => array( 'hash' => str_repeat( 'a', 64 ) ),
			'pg_b' => array( 'hash' => str_repeat( 'b', 64 ) ),
			'pg_c' => array( 'hash' => str_repeat( 'c', 64 ) ),
		);
	}

	public function test_first_sync_fetches_everything(): void {
		$plan = Plan::make( $this->pages(), array() );

		$this->assertSame( array( 'pg_a', 'pg_b', 'pg_c' ), $plan->fetch );
		$this->assertSame( array(), $plan->delete );
	}

	public function test_only_changed_new_and_unfetched_pages_are_fetched(): void {
		$plan = Plan::make(
			$this->pages(),
			array(
				'pg_a' => str_repeat( 'a', 64 ),
				'pg_b' => str_repeat( 'x', 64 ),
				'pg_c' => '',
				'pg_d' => str_repeat( 'd', 64 ),
			)
		);

		$this->assertSame( array( 'pg_b', 'pg_c' ), $plan->fetch );
		$this->assertSame( array( 'pg_d' ), $plan->delete );
	}

	public function test_a_renamed_page_keeps_its_id_and_is_not_deleted(): void {
		$pages = $this->pages();
		$plan  = Plan::make( $pages, array_map( static fn ( $p ) => $p['hash'], $pages ) );

		$this->assertSame( array(), $plan->fetch );
		$this->assertSame( array(), $plan->delete );
	}

	public function test_stale_revisions(): void {
		$this->assertTrue( Plan::is_stale( 11, 12 ) );
		$this->assertFalse( Plan::is_stale( 12, 12 ) );
		$this->assertFalse( Plan::is_stale( 13, 12 ) );
		$this->assertFalse( Plan::is_stale( 1, -1 ) );
	}
}
