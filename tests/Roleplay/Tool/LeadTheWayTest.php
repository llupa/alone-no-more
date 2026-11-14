<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\BagsInterface;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\LevelProgressInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Tool\BotCommandContext;
use App\Roleplay\Tool\CompanionTools;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journeys;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Model\Position;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Walking someone to an NPC.
 *
 * Knowing where Deputy Willem stands and being taken to him are different requests, and the
 * second is the one a player actually wants when they are lost.
 */
final class LeadTheWayTest extends TestCase
{
    /**
     * Following comes off first, then the mod's own travel system is asked for the place by
     * name. A forced travel target survives across ticks; a one-shot move order does not,
     * which is why two earlier attempts failed in play.
     */
    public function testItStopsFollowingAndTravelsToTheNamedPlace(): void
    {
        $sent = [];
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            return 'ok';
        });

        $answer = $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]])->leadTheWay('Deputy Willem');

        // The walk is handed to the bot's own StayStrategy, anchored at the destination,
        // because one-shot orders lose to a strategy that runs every tick. `follow` resets
        // both engines and both recorded positions, `nc -follow` stops the following that
        // reset just turned on, the anchor moves to the target, and only then is `stay`
        // switched on — anchor before strategy, or it holds the spot it is standing on.
        self::assertSame('playerbots exec Menelan !follow', $sent[0] ?? null);
        self::assertSame('playerbots exec Menelan !nc -follow', $sent[1] ?? null);
        // 95 yards away, so the whole walk fits in one leg and the anchor is the destination.
        self::assertSame('playerbots exec Menelan !position stay 95,0,50', $sent[2] ?? null);
        self::assertSame('playerbots exec Menelan !nc +stay', $sent[3] ?? null);
        self::assertStringContainsString('Deputy Willem', $answer);
    }

    /**
     * A journey is hot memory that has to be let go when something else owns the feet.
     *
     * Without this the container outlived what it described: "follow me" during a walk gave a
     * companion that followed for one second, then re-anchored itself on the next leg because
     * the journey was still open. The walk has to end when it is superseded.
     */
    public function testAnOrderThatTakesTheFeetEndsTheWalk(): void
    {
        $sent = [];
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            return 'ok';
        });

        $journeys = new Journeys($world, new PlayerbotCommandRunner($world));
        $tools = $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]], $journeys);

        $tools->leadTheWay('Deputy Willem');
        self::assertContains('playerbots exec Menelan !nc +stay', $sent, 'the walk should have started');

        $tools->doThis('follow');
        $afterTheOrder = \count($sent);

        // Nothing left walking, so nothing re-anchors on the next pass.
        self::assertSame([], $journeys->tick());
        self::assertCount($afterTheOrder, $sent);
    }

    /**
     * The leash, and why the feature refuses rather than pretends.
     *
     * `Playerbots.ReactDistance` — 150 yards as shipped — is a hard limit on every "go there"
     * mechanism the mod has: a stay anchor beyond it is discarded by
     * `ReturnToStayPositionAction::isPossible()`, and `GoAction`'s coordinate form refuses with
     * "It is too far away". Six rewrites tried to get past it and the traces were unambiguous:
     * inside the leash a companion arrives every time, outside it the distance never changes by
     * a yard. Announcing a walk and then standing still is worse than saying no.
     */
    public function testSomewhereTooFarIsRefusedRatherThanPromised(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        $answer = $this->tools($world, [['name' => 'Marshal Dughan', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 400.0, 'y' => 0.0]])->leadTheWay('Marshal Dughan');

        self::assertStringContainsString('too far', $answer);
        self::assertStringNotContainsString('You are walking', $answer);
    }

    /**
     * Inside the leash it still walks, which is most of what players ask for.
     */
    public function testSomewhereNearbyIsStillWalkedTo(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturn('ok');

        $answer = $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]])->leadTheWay('Deputy Willem');

        self::assertStringContainsString('You are walking', $answer);
    }

    /**
     * `go travel <name>` is deliberately not used any more, and this pins that.
     *
     * It whispers "Clearing travel target" and returns *success* whenever the travel system
     * does not recognise the name (`GoAction.cpp`), which it does not for most NPCs. Nothing
     * threw, so the coordinate fallback never ran and the companion simply stood still while
     * reporting that it was on its way.
     */
    public function testItDoesNotRelyOnTheModsTravelSystem(): void
    {
        $sent = [];
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            return 'ok';
        });

        $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]])->leadTheWay('Deputy Willem');

        foreach ($sent as $command) {
            self::assertStringNotContainsString('go travel', $command);
        }
    }

    public function testSomebodyUnknownIsNotWalkedTo(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        self::assertStringContainsString(
            'nobody called "Arthas"',
            $this->tools($world, [])->leadTheWay('Arthas'),
        );
    }

    /**
     * `go` pathfinds on one map. Setting off toward Orgrimmar from Elwynn would have a companion
     * walk into the sea.
     */
    public function testSomebodyOnAnotherContinentIsRefused(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->expects(self::never())->method('execute');

        $answer = $this->tools($world, [['name' => 'Thrall', 'level_min' => 80, 'level_max' => 80,
            'spawns' => 1, 'map' => 1, 'x' => 1000.0, 'y' => 1000.0]])->leadTheWay('Thrall');

        self::assertStringContainsString('another continent', $answer);
    }

    /**
     * The sequence that failed in play, and the reason the fix spans two classes.
     *
     * A companion that had arrived somewhere was told to `stay`, and `stay` is a movement
     * strategy that returns the bot to its anchor every tick. Leading anywhere afterwards then
     * did nothing at all — four times over in one session — because setting off only removed
     * `follow`. Nothing in a single journey shows this; it only appears on the second one.
     */
    public function testASecondJourneyAfterArrivingStillSetsOff(): void
    {
        $sent = [];
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            return 'ok';
        });

        $tools = $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]]);

        $tools->leadTheWay('Deputy Willem');
        $secondJourney = \count($sent);

        $tools->leadTheWay('Deputy Willem');

        self::assertSame('playerbots exec Menelan !follow', $sent[$secondJourney] ?? null);
        self::assertSame('playerbots exec Menelan !nc -follow', $sent[$secondJourney + 1] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $creatures
     */
    private function tools(WorldGatewayInterface $world, array $creatures, ?Journeys $journeys = null): CompanionTools
    {
        $knowledge = $this->createStub(GameKnowledgeInterface::class);
        $knowledge->method('findCreatures')->willReturn($creatures);

        $context = new BotCommandContext();
        $context->enter(
            ActorMother::bot(1, 'Menelan', at: new Position(0.0, 0.0, 50.0, 0)),
            ActorMother::player(100),
            null,
        );

        return new CompanionTools(
            $context,
            new PlayerbotCommandRunner($world),
            $this->createStub(QuestLogInterface::class),
            $this->createStub(BagsInterface::class),
            $knowledge,
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            $journeys ?? new Journeys($world, new PlayerbotCommandRunner($world)),
        );
    }
}
