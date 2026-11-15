<?php

declare(strict_types=1);

namespace App\Tests\Director;

use App\Director\CompanionRequests;
use App\Director\CompanionsOnCall;
use App\Herding\BotRoster;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Position;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Making good on a companion asked for in advance.
 *
 * Asking for someone by name is the opposite of the herder's anonymous crowd, so "they are
 * online somewhere" is not the same as "they are here" — and the difference is what this is
 * about.
 */
#[CoversClass(CompanionsOnCall::class)]
final class CompanionsOnCallTest extends TestCase
{
    private const PLAYER = 2503;
    private const BOT = 1286;

    /**
     * The gap that made the feature look broken.
     *
     * A booked companion was skipped whenever they were online at all, and mod-playerbots'
     * random-bot manager walks bots wherever it likes while the player is logged out. After a
     * server restart the player found their priest two zones away in Duskwood, with nothing
     * bringing them back: asking for someone in advance is pointless if they then have to be
     * hunted down.
     */
    public function testACompanionWhoWanderedOffIsSentAwayToBeBroughtBack(): void
    {
        $queued = [];
        $outbox = $this->outboxRecording($queued);

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(
            ActorMother::bot(self::BOT, 'Anduril', at: new Position(-10607.0, 294.0, 40.0, 0), zoneId: 10),
        );

        $onCall = new CompanionsOnCall(
            $this->requestsFor(),
            $world,
            new BotRoster($this->createStub(Connection::class)),
            new BotSpeechDispatcher($outbox),
            $this->createStub(GroupRepositoryInterface::class),
        );

        $onCall->gather(ActorMother::player(self::PLAYER, at: new Position(-9894.0, 344.0, 40.0, 0), zoneId: 12));

        self::assertSame(['logout'], array_column($queued, 'action'));
        self::assertSame(self::BOT, $queued[0]['bot_guid']);
    }

    /**
     * Someone already standing with the player is left alone — no logging out and back in just
     * because they stepped a few paces away.
     */
    public function testACompanionAlreadyPresentIsLeftAlone(): void
    {
        $queued = [];
        $outbox = $this->outboxRecording($queued);

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(
            ActorMother::bot(self::BOT, 'Anduril', at: new Position(-9880.0, 350.0, 40.0, 0), zoneId: 12),
        );

        $onCall = new CompanionsOnCall(
            $this->requestsFor(),
            $world,
            new BotRoster($this->createStub(Connection::class)),
            new BotSpeechDispatcher($outbox),
            $this->createStub(GroupRepositoryInterface::class),
        );

        self::assertSame([], $onCall->gather(
            ActorMother::player(self::PLAYER, at: new Position(-9894.0, 344.0, 40.0, 0), zoneId: 12),
        ));
        self::assertSame([], $queued);
    }

    /**
     * A companion walking somewhere on purpose looks exactly like one who has wandered off, and
     * hauling them back mid-journey is worse than leaving them: the player watched their priest
     * vanish from the road and reappear beside them — "did you bug out and fly to Goldshire?".
     */
    public function testACompanionWhoIsLeadingTheWayIsLeftWhereTheyAre(): void
    {
        $queued = [];
        $outbox = $this->outboxRecording($queued);

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(
            ActorMother::bot(self::BOT, 'Anduril', at: new Position(-10607.0, 294.0, 40.0, 0), zoneId: 10),
        );

        $onCall = new CompanionsOnCall(
            $this->requestsFor(),
            $world,
            new BotRoster($this->createStub(Connection::class)),
            new BotSpeechDispatcher($outbox),
            $this->createStub(GroupRepositoryInterface::class),
        );

        $onCall->gather(
            ActorMother::player(self::PLAYER, at: new Position(-9894.0, 344.0, 40.0, 0), zoneId: 12),
            busy: [self::BOT],
        );

        self::assertSame([], $queued);
    }

    /**
     * A companion in the player's party is never hauled back, whatever the distance.
     *
     * Logging a bot out removes it from the group, so "fixing" where a party member stands
     * disbands the party to do it. The player hit this on every zone change: Anduril went
     * offline and the group fell apart, because crossing a border counted as wandering off.
     */
    public function testAGroupedCompanionIsNeverLoggedOut(): void
    {
        $queued = [];
        $outbox = $this->outboxRecording($queued);

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(
            ActorMother::bot(self::BOT, 'Anduril', at: new Position(-10607.0, 294.0, 40.0, 0), zoneId: 10),
        );

        $groups = $this->createStub(GroupRepositoryInterface::class);
        $groups->method('isGrouped')->willReturn(true);

        $onCall = new CompanionsOnCall(
            $this->requestsFor(),
            $world,
            new BotRoster($this->createStub(Connection::class)),
            new BotSpeechDispatcher($outbox),
            $groups,
        );

        $onCall->gather(ActorMother::player(self::PLAYER, at: new Position(-9894.0, 344.0, 40.0, 0), zoneId: 12));

        self::assertSame([], $queued);
    }

    /**
     * Crossing a zone border is not wandering off. Zone ids are arbitrary lines on the ground
     * and a companion ten yards away across one is plainly still with you.
     */
    public function testStandingBesideThePlayerInAnotherZoneIsStillHere(): void
    {
        $queued = [];
        $outbox = $this->outboxRecording($queued);

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(
            ActorMother::bot(self::BOT, 'Anduril', at: new Position(-9890.0, 350.0, 40.0, 0), zoneId: 10),
        );

        $onCall = new CompanionsOnCall(
            $this->requestsFor(),
            $world,
            new BotRoster($this->createStub(Connection::class)),
            new BotSpeechDispatcher($outbox),
            $this->createStub(GroupRepositoryInterface::class),
        );

        $onCall->gather(ActorMother::player(self::PLAYER, at: new Position(-9894.0, 344.0, 40.0, 0), zoneId: 12));

        self::assertSame([], $queued);
    }

    private function requestsFor(): CompanionRequests
    {
        $requests = new CompanionRequests(tempnam(sys_get_temp_dir(), 'anm-oncall-').'.sqlite');
        $requests->add(self::PLAYER, 'Klemprime', self::BOT, 'Anduril', 'a priest of my level');

        return $requests;
    }

    /**
     * @param array<int, array<string, mixed>> $queued
     */
    private function outboxRecording(array &$queued): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$queued): int {
                $queued[] = $data;

                return 1;
            },
        );
        $connection->method('lastInsertId')->willReturn('1');

        return $connection;
    }
}
