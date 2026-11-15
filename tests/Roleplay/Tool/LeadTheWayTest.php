<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\BagsInterface;
use App\Knowledge\Dbc\ZoneBounds;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\LevelProgressInterface;
use App\Knowledge\MerchantsInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Tool\BotCommandContext;
use App\Roleplay\Tool\CompanionTools;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journeys;
use App\Roleplay\Travel\LeadOrders;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Model\Position;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Walking someone to an NPC.
 *
 * Knowing where Deputy Willem stands and being taken to him are different requests, and the
 * second is the one a player actually wants when they are lost. Hallway testers rated it the
 * best thing in the project, which is the only reason it survived six failed attempts.
 *
 * The walk itself is mod-anm-bridge's now — see {@see LeadOrders} — so what is checked here is
 * the order being placed, and nothing being claimed that the server did not accept.
 */
final class LeadTheWayTest extends TestCase
{
    /**
     * The destination is the NPC, stopped a few paces short so the companion does not stand
     * inside the person the player wanted to talk to.
     */
    public function testItOrdersTheWalkAndSaysWhereItIsGoing(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);

        $answer = $this->tools($connection, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0, 'z' => 42.0]])->leadTheWay('Deputy Willem');

        self::assertCount(1, $queued);
        self::assertSame('lead', $queued[0]['action']);
        self::assertSame('95.00;0.00;42.00', $queued[0]['message']);
        self::assertSame(1, $queued[0]['bot_guid']);
        self::assertStringContainsString('Deputy Willem', $answer);
    }

    /**
     * No leash any more, and this is the case that proves it.
     *
     * Every previous attempt drove the walk through mod-playerbots' chat commands, all of which
     * are capped at `Playerbots.ReactDistance` — 150 yards. Four hundred yards used to be
     * refused outright, after six rewrites failed to make it work.
     */
    public function testSomewhereFarAwayIsNoLongerRefused(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);

        $answer = $this->tools($connection, [['name' => 'Marshal Dughan', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 400.0, 'y' => 0.0, 'z' => 42.0]])->leadTheWay('Marshal Dughan');

        self::assertCount(1, $queued);
        self::assertSame('395.00;0.00;42.00', $queued[0]['message']);
        self::assertStringContainsString('walking', $answer);
    }

    /**
     * The module knows things this side cannot — whether the bot was online, whether the
     * destination parsed. A companion that announces a journey the server refused is the exact
     * failure this project kept producing, so the refusal is reported rather than swallowed.
     */
    public function testARefusalIsReportedRatherThanAnnounced(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('lastInsertId')->willReturn('7');
        $connection->method('fetchAssociative')->willReturn(['status' => 'failed', 'error' => 'bot not online']);

        $answer = $this->tools($connection, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0, 'z' => 42.0]])->leadTheWay('Deputy Willem');

        self::assertStringContainsString('could not set off', $answer);
        self::assertStringContainsString('bot not online', $answer);
        self::assertStringNotContainsString('You are walking', $answer);
    }

    /**
     * Cities are refused, and that is the feature rather than a gap in it.
     *
     * The server's navigation is reliable in open country and not in a capital — doors, lifts,
     * stacked floors. Standing in Stormwind and asked for a guard out in Elwynn, a companion
     * stood still through 1631 yards of apology because every route out ran through a wall.
     * Saying so beats promising a walk that will not happen.
     */
    public function testItWillNotLeadThroughACity(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);

        $answer = $this->tools(
            $connection,
            [['name' => 'Guard Thomas', 'level_min' => 5, 'level_max' => 5,
                'spawns' => 1, 'map' => 0, 'x' => 400.0, 'y' => 0.0, 'z' => 42.0]],
            botZoneId: 1519,
        )->leadTheWay('Guard Thomas');

        self::assertSame([], $queued, 'nothing should have been ordered');
        self::assertStringContainsString('Stormwind City', $answer);
        self::assertStringNotContainsString('You are walking', $answer);
    }

    public function testSomebodyUnknownIsNotWalkedTo(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);

        $answer = $this->tools($connection, [])->leadTheWay('Arthas');

        self::assertSame([], $queued);
        self::assertStringContainsString('nobody called "Arthas"', $answer);
    }

    public function testSomewhereOnAnotherContinentIsRefused(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);

        $answer = $this->tools($connection, [['name' => 'Thrall', 'level_min' => 80, 'level_max' => 80,
            'spawns' => 1, 'map' => 1, 'x' => 1000.0, 'y' => 1000.0, 'z' => 42.0]])->leadTheWay('Thrall');

        self::assertSame([], $queued);
        self::assertStringContainsString('another continent', $answer);
    }

    /**
     * A walk now has two halves — the module doing the walking and the watcher waiting to
     * announce the arrival — and an order that takes the companion's feet has to end both.
     * Stopping only the watcher leaves a bot that keeps walking after being told to follow.
     */
    public function testAnOrderThatTakesTheFeetEndsBothHalvesOfTheWalk(): void
    {
        $queued = [];
        $connection = $this->outboxThatAccepts($queued);
        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('execute')->willReturn('ok');

        $journeys = new Journeys($world, new PlayerbotCommandRunner($world));
        $tools = $this->tools($connection, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0, 'z' => 42.0]], $journeys, $world);

        $tools->leadTheWay('Deputy Willem');
        $tools->doThis('follow');

        self::assertSame(['lead', 'unlead'], array_column($queued, 'action'));
        self::assertSame([], $journeys->tick(), 'the watcher should have forgotten the walk too');
    }

    /**
     * A connection that accepts every order, recording what was queued.
     *
     * @param array<int, array<string, mixed>> $queued
     */
    private function outboxThatAccepts(array &$queued): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$queued): int {
                $queued[] = $data;

                return 1;
            },
        );
        $connection->method('lastInsertId')->willReturn('1');
        $connection->method('fetchAssociative')->willReturn(['status' => 'done', 'error' => '']);

        return $connection;
    }

    /**
     * @param array<int, array<string, mixed>> $creatures
     */
    private function tools(
        Connection $connection,
        array $creatures,
        ?Journeys $journeys = null,
        ?WorldGatewayInterface $world = null,
        int $botZoneId = 12,
    ): CompanionTools {
        $world ??= $this->createStub(WorldGatewayInterface::class);

        $knowledge = $this->createStub(GameKnowledgeInterface::class);
        $knowledge->method('findCreatures')->willReturn($creatures);

        $context = new BotCommandContext();
        $context->enter(
            ActorMother::bot(1, 'Menelan', at: new Position(0.0, 0.0, 50.0, 0), zoneId: $botZoneId),
            ActorMother::player(100),
            null,
        );

        return new CompanionTools(
            $context,
            new PlayerbotCommandRunner($world),
            $this->createStub(QuestLogInterface::class),
            $this->createStub(BagsInterface::class),
            $this->createStub(MerchantsInterface::class),
            $knowledge,
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            $journeys ?? new Journeys($world, new PlayerbotCommandRunner($world)),
            new LeadOrders(new Outbox($connection)),
            new ZoneBounds('/nonexistent'),
        );
    }
}
