<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Connections;
use Bristlecone\Zfolio\Cron;
use Bristlecone\Zfolio\Sync;
use PHPUnit\Framework\TestCase;

/**
 * Scheduling against in-memory options and cron (see tests/Support/stubs.php).
 */
final class CronTest extends TestCase {

	private const ID     = 'cabcdefghi';
	private const SECRET = 'zfwh_abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

	protected function setUp(): void {
		$GLOBALS['zf_test_options'] = array();
		$GLOBALS['zf_test_cron']    = array();
		$GLOBALS['zf_test_spawned'] = 0;
	}

	private function connection( int $interval, string $secret = self::SECRET ): void {
		Connections::save(
			self::ID,
			array(
				'address'        => 'https://zfolio.zuuid.cc/feed/v1/zff_test',
				'interval'       => $interval,
				'webhook_secret' => $secret,
			)
		);
	}

	private function next(): int|false {
		return wp_next_scheduled( Cron::HOOK, array( self::ID ) );
	}

	public function test_webhook_only_needs_a_secret_to_be_saved(): void {
		$this->connection( Connections::WEBHOOK_ONLY, '' );
		$this->assertSame( 15, Connections::get( self::ID )['interval'] );

		$this->connection( Connections::WEBHOOK_ONLY );
		$this->assertSame( Connections::WEBHOOK_ONLY, Connections::get( self::ID )['interval'] );
	}

	public function test_daily_and_weekly_are_kept(): void {
		$this->connection( Connections::DAILY );
		$this->assertSame( Connections::DAILY, Connections::get( self::ID )['interval'] );

		$this->connection( Connections::WEEKLY );
		$this->assertSame( Connections::WEEKLY, Connections::get( self::ID )['interval'] );
	}

	public function test_regular_poll_is_scheduled_for_daily(): void {
		$this->connection( Connections::DAILY );
		Cron::schedule( self::ID );

		$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS, $this->next() );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS + 90, $this->next() );
	}

	public function test_webhook_only_clears_the_regular_poll(): void {
		$this->connection( 15 );
		Cron::schedule( self::ID );
		$this->assertNotFalse( $this->next() );

		$this->connection( Connections::WEBHOOK_ONLY );
		Cron::schedule( self::ID );
		$this->assertFalse( $this->next() );
	}

	public function test_webhook_only_keeps_unfinished_work(): void {
		$this->connection( Connections::WEBHOOK_ONLY );
		Connections::update_state( self::ID, array( 'pending' => true ) );
		Cron::schedule( self::ID, Sync::CONTINUE_AFTER );
		$at = $this->next();

		Cron::schedule( self::ID );
		$this->assertSame( $at, $this->next() );

		$GLOBALS['zf_test_cron'] = array();
		Cron::schedule( self::ID );
		$this->assertGreaterThanOrEqual( time() + Sync::CONTINUE_AFTER, $this->next() );
	}

	public function test_webhook_only_waits_out_retry_until(): void {
		$this->connection( Connections::WEBHOOK_ONLY );
		Connections::update_state( self::ID, array( 'retry_until' => time() + 600 ) );
		Cron::schedule( self::ID );

		$this->assertEqualsWithDelta( time() + 600, $this->next(), 1 );
	}

	public function test_explicit_delays_still_run_on_webhook_only(): void {
		$this->connection( Connections::WEBHOOK_ONLY );
		Cron::schedule( self::ID, 90 );

		$this->assertEqualsWithDelta( time() + 90, $this->next(), 1 );
	}

	public function test_ensure_skips_webhook_only(): void {
		$this->connection( Connections::WEBHOOK_ONLY );
		Cron::ensure();
		$this->assertFalse( $this->next() );

		$this->connection( 30 );
		Cron::ensure();
		$this->assertNotFalse( $this->next() );
	}

	public function test_now_schedules_resets_retries_and_spawns_cron(): void {
		$this->connection( Connections::WEBHOOK_ONLY );
		Connections::update_state( self::ID, array( 'retries' => 3 ) );
		Cron::now( self::ID );

		$this->assertEqualsWithDelta( time(), $this->next(), 1 );
		$this->assertSame( 0, Connections::state( self::ID )['retries'] );
		$this->assertSame( 1, $GLOBALS['zf_test_spawned'] );
	}
}
