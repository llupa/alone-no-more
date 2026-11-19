<?php

declare(strict_types=1);

namespace App\Tests\Herding;

use App\Herding\StagingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One line about what the herder did — read in the listener's log, by anm:herd, and by an
 * assistant over MCP.
 */
#[CoversClass(StagingResult::class)]
final class StagingResultTest extends TestCase
{
    public function testNothingToDoSaysSo(): void
    {
        $result = new StagingResult(alreadyPresent: 50, target: 50, broughtIn: [], sentAway: []);

        self::assertSame('50 of 50 present, nothing to do.', $result->summary());
    }

    /**
     * Nothing moved because nothing could. Against the recording that read "37 of 0 present,
     * nothing to do", which sounds like a working herder with a strange target.
     */
    public function testNothingMovingBecauseNothingCouldSaysWhy(): void
    {
        $result = new StagingResult(
            alreadyPresent: 37,
            target: 0,
            broughtIn: [],
            sentAway: [],
            limitedBy: 'this is a recording, so nobody can be brought in or sent away',
        );

        self::assertSame('37 present; this is a recording, so nobody can be brought in or sent away.', $result->summary());
    }

    public function testAChangeIsCountedAndItsLimitNamed(): void
    {
        $result = new StagingResult(
            alreadyPresent: 40,
            target: 50,
            broughtIn: [['guid' => 1, 'name' => 'Ibykick', 'level' => 15]],
            sentAway: [],
            limitedBy: 'ran out of suitable dormant bots',
        );

        self::assertSame('40 present, brought in 1, sent away 0 (limited by ran out of suitable dormant bots).', $result->summary());
    }
}
