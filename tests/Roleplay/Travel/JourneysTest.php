<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Travel;

use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journey;
use App\Roleplay\Travel\Journeys;
use App\Roleplay\Travel\LeadOrders;
use App\Tests\Roleplay\ActorMother;
use App\World\Model\Position;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Noticing how a walk ended.
 *
 * The walking itself belongs to mod-anm-bridge. What is left out here is noticing the arrival,
 * hearing when the module gave up, and a backstop for a walk nobody has heard from in far too
 * long.
 */
#[CoversClass(Journeys::class)]
#[CoversClass(Journey::class)]
#[CoversClass(LeadOrders::class)]
#[CoversClass(Outbox::class)]
final class JourneysTest extends TestCase
{
    private const BOT = 1;

    /**
     * @param array<int, array<string, mixed>>          $queued   what was put in the outbox
     * @param array{status: string, error: string}|null $lastLead how the latest walk order stands
     */
    private function journeys(WorldGatewayInterface $world, array &$queued = [], ?array $lastLead = null): Journeys
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$queued): int {
                $queued[] = $data;

                return 1;
            },
        );
        $connection->method('lastInsertId')->willReturn('1');
        $connection->method('fetchAssociative')->willReturn($lastLead ?? ['status' => 'done', 'error' => '']);

        return new Journeys($world, new PlayerbotCommandRunner($world), new LeadOrders(new Outbox($connection)));
    }

    private function worldWithBotAt(Position $at): WorldGatewayInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(ActorMother::bot(self::BOT, 'Menelan', at: $at));

        return $world;
    }

    /**
     * Arriving is only noticed here, never acted on: the module stops the companion where it
     * gets to and forgets the destination itself, so there is nothing left to send.
     */
    public function testArrivingIsAnnouncedWithoutSendingAnything(): void
    {
        $world = $this->worldWithBotAt(new Position(100.0, 0.0, 50.0, 0));
        $world->expects(self::never())->method('execute');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(self::BOT, new Position(103.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true)));

        $news = $journeys->tick();

        self::assertStringContainsString('reached Deputy Willem', $news[self::BOT] ?? '');
    }

    /**
     * Still walking is not news, and nothing is sent either — every attempt to help the walk
     * along from out here made it worse.
     */
    public function testStillWalkingIsNotReported(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->method('execute')->willReturn('ok');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(self::BOT, new Position(500.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true)));

        self::assertSame([], $journeys->tick());
    }

    /**
     * The module decides when a walk has failed: it can tell slow progress round a lake from
     * jogging into a rock, which a timer out here cannot. It has already stopped the companion
     * and put them back to following, so the only thing left is to say so.
     */
    public function testTheModuleGivingUpIsReportedWithItsReason(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->expects(self::never())->method('execute');

        $queued = [];
        $journeys = $this->journeys($world, $queued, ['status' => 'failed', 'error' => 'lost the way 312 yards short']);
        $journeys->start(new Journey(self::BOT, new Position(500.0, 0.0, 50.0, 0), 'Deputy Willem', microtime(true)));

        $news = $journeys->tick();

        self::assertStringContainsString('could not reach Deputy Willem: lost the way 312 yards short', $news[self::BOT] ?? '');
        self::assertSame([], $queued, 'the module already ended the walk; nothing to send');
        self::assertSame([], $journeys->tick(), 'and it is not reported twice');
    }

    /**
     * A walk the module has said nothing about for far too long. It may still be driving it,
     * so the walk is ended there before following is switched back on — otherwise the two
     * pull the companion in different directions.
     */
    public function testOurOwnTimeoutEndsTheWalkInTheModuleThenFollows(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !follow')
            ->willReturn('ok');

        $queued = [];
        $journeys = $this->journeys($world, $queued);
        $journeys->start(new Journey(
            self::BOT, new Position(5000.0, 0.0, 50.0, 0), 'Deputy Willem',
            startedAt: microtime(true) - 1000.0,
        ));

        self::assertStringContainsString('could not reach', $journeys->tick()[self::BOT] ?? '');
        self::assertSame(['unlead'], array_column($queued, 'action'));
        self::assertSame([], $journeys->tick(), 'and it is not reported twice');
    }

    /**
     * Three minutes used to be the limit, and it cut off walks that were going fine. A long
     * walk the module is still driving is still a walk.
     */
    public function testALongWalkStillGoingIsNotCutOff(): void
    {
        $world = $this->worldWithBotAt(new Position(0.0, 0.0, 50.0, 0));
        $world->expects(self::never())->method('execute');

        $journeys = $this->journeys($world);
        $journeys->start(new Journey(
            self::BOT, new Position(5000.0, 0.0, 50.0, 0), 'Deputy Willem',
            startedAt: microtime(true) - 300.0,
        ));

        self::assertSame([], $journeys->tick());
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
