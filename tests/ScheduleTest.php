<?php
declare(strict_types=1);

namespace Bristlecone\Zfolio\Tests;

use Bristlecone\Zfolio\Connections;
use Bristlecone\Zfolio\Cron;
use Bristlecone\Zfolio\Sync;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

	private const SECRET = 'zfwh_secret';

	public function test_intervals_list_every_choice_in_screen_order(): void {
		$this->assertSame( array( 5, 15, 30, 60, 1440, 10080, 0 ), Connections::INTERVALS );
	}

	public function test_polling_intervals_wait_the_interval_plus_jitter(): void {
		foreach ( array( 5, 15, 30, 60, Connections::DAILY, Connections::WEEKLY ) as $minutes ) {
			$delay = Cron::regular_delay( $minutes, false, 0, 1000 );

			$this->assertGreaterThanOrEqual( $minutes * 60, $delay );
			$this->assertLessThanOrEqual( $minutes * 60 + 90, $delay );
		}
	}

	public function test_polling_ignores_pending_work_for_the_regular_poll(): void {
		$delay = Cron::regular_delay( Connections::DAILY, true, 0, 1000 );

		$this->assertGreaterThanOrEqual( Connections::DAILY * 60, $delay );
	}

	public function test_webhook_only_has_no_regular_poll(): void {
		$this->assertNull( Cron::regular_delay( Connections::WEBHOOK_ONLY, false, 0, 1000 ) );
		$this->assertNull( Cron::regular_delay( Connections::WEBHOOK_ONLY, false, 999, 1000 ) );
	}

	public function test_webhook_only_keeps_unfinished_work(): void {
		$this->assertSame( Sync::CONTINUE_AFTER, Cron::regular_delay( Connections::WEBHOOK_ONLY, true, 0, 1000 ) );
		$this->assertSame( 120, Cron::regular_delay( Connections::WEBHOOK_ONLY, false, 1120, 1000 ) );
		$this->assertSame( 120, Cron::regular_delay( Connections::WEBHOOK_ONLY, true, 1120, 1000 ) );
	}

	public function test_retries_back_off_then_stop(): void {
		$this->assertNull( Cron::retry_delay( 0 ) );
		$this->assertSame( 300, Cron::retry_delay( 1 ) );
		$this->assertSame( 600, Cron::retry_delay( 2 ) );
		$this->assertSame( 1200, Cron::retry_delay( 3 ) );
		$this->assertSame( 2400, Cron::retry_delay( 4 ) );
		$this->assertSame( 4800, Cron::retry_delay( Cron::MAX_RETRIES ) );
		$this->assertNull( Cron::retry_delay( Cron::MAX_RETRIES + 1 ) );
	}

	public function test_webhook_only_needs_a_secret_and_a_signed_event(): void {
		$this->assertFalse( Connections::can_use_webhook_only( array( 'webhook_secret' => '' ), array( 'last_webhook' => 1000 ) ) );
		$this->assertFalse( Connections::can_use_webhook_only( array( 'webhook_secret' => self::SECRET ), array( 'last_webhook' => 0 ) ) );
		$this->assertFalse( Connections::can_use_webhook_only( array( 'webhook_secret' => self::SECRET ), array() ) );
		$this->assertTrue( Connections::can_use_webhook_only( array( 'webhook_secret' => self::SECRET ), array( 'last_webhook' => 1000 ) ) );
	}

	public function test_quiet_warning_only_for_webhook_only_after_seven_days(): void {
		$now  = 100 * 86400;
		$hook = array( 'interval' => Connections::WEBHOOK_ONLY );

		$this->assertFalse( Connections::webhook_quiet( $hook, array( 'last_webhook' => $now - 7 * 86400 ), $now ) );
		$this->assertTrue( Connections::webhook_quiet( $hook, array( 'last_webhook' => $now - 7 * 86400 - 1 ), $now ) );
		$this->assertTrue( Connections::webhook_quiet( $hook, array( 'last_webhook' => 0 ), $now ) );
		$this->assertFalse( Connections::webhook_quiet( array( 'interval' => Connections::DAILY ), array( 'last_webhook' => 0 ), $now ) );
		$this->assertFalse( Connections::webhook_quiet( array( 'interval' => 15 ), array( 'last_webhook' => 1 ), $now ) );
	}
}
