<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Travel;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journey;
use App\Roleplay\Travel\Journeys;
use App\Tests\Roleplay\ActorMother;
use App\World\Model\Position;
use App\World\WorldGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Walking somewhere and staying there.
 *
 * `go` is one movement order. A companion that is also following its master drifts back the
 * moment the walk ends, so arriving has to be noticed and answered with `stay`.
 */
#[CoversClass(Journeys::class)]
#[CoversClass(Journey::class)]
final class JourneysTest extends TestCase
{
    private const BOT = 1;

    private function journeys(WorldGatewayInterface $world): Journeys
    {
        return new Journeys($world, new PlayerbotCommandRunner($world));
    }

    private function worldWithBotAt(Position $at): WorldGatewayInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(ActorMother::bot(self::BOT, 'Menelan', at: $at));

        return $world;
    }

    public function testArrivingTellsTheCompanionToWait(): void
    {
        $world = $this->worldWithBotAt(new Position(100.0, 0.0, 50.0, 0));
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !stay')
            ->willReturn('ok');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(self::BOT, new Position(103.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true)));

        $news = $journeys->tick();

        self::assertStringContainsString('reached Deputy Willem', $news[self::BOT] ?? '');
    }

    public function testStillWalkingIsLeftAlone(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->expects(self::never())->method('execute');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(self::BOT, new Position(500.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true)));

        self::assertSame([], $journeys->tick());
    }

    /**
     * A walk that never finishes must not leave a companion marked as travelling for ever —
     * nor standing in a field. Following was switched off to let them walk, so giving up has to
     * switch it back on.
     */
    public function testGivingUpPutsTheCompanionBackToFollowing(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !follow')
            ->willReturn('ok');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(
            self::BOT, new Position(5000.0, 0.0, 50.0, 0), 'Deputy Willem',
            startedAt: microtime(true) - 300.0,
        ));

        self::assertStringContainsString('could not reach', $journeys->tick()[self::BOT] ?? '');
        self::assertSame([], $journeys->tick(), 'and it is not reported twice');
    }

    /**
     * Same coordinates on a different map is not the same place.
     */
    public function testArrivalIsPerMap(): void
    {
        $journey = new Journey(self::BOT, new Position(100.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true));

        self::assertTrue($journey->hasArrived(new Position(100.0, 0.0, 50.0, 0)));
        self::assertFalse($journey->hasArrived(new Position(100.0, 0.0, 50.0, 1)));
    }
}
