<?php

declare(strict_types=1);

namespace App\Tests\World;

use App\World\SaveSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A full save of every character once a second was the listener's heaviest habit. Every few
 * seconds does, except when someone has just spoken.
 */
#[CoversClass(SaveSchedule::class)]
final class SaveScheduleTest extends TestCase
{
    public function testTheFirstPassAlwaysSaves(): void
    {
        self::assertTrue((new SaveSchedule(5.0))->isDue(100.0, chatWaiting: false));
    }

    public function testQuietPassesSaveEverySoOften(): void
    {
        $schedule = new SaveSchedule(5.0);
        $schedule->isDue(100.0, chatWaiting: false);

        self::assertFalse($schedule->isDue(101.0, chatWaiting: false));
        self::assertFalse($schedule->isDue(104.9, chatWaiting: false));
        self::assertTrue($schedule->isDue(105.0, chatWaiting: false));
        self::assertFalse($schedule->isDue(106.0, chatWaiting: false), 'the clock restarts from the last save');
    }

    /**
     * Who hears a /say depends on where everyone stands now, not five seconds ago.
     */
    public function testChatWaitingToBeAnsweredAlwaysSaves(): void
    {
        $schedule = new SaveSchedule(5.0);
        $schedule->isDue(100.0, chatWaiting: false);

        self::assertTrue($schedule->isDue(101.0, chatWaiting: true));
        self::assertFalse($schedule->isDue(102.0, chatWaiting: false), 'and that save counts');
    }
}
