<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

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

        self::assertSame('playerbots exec Menelan !nc -follow', $sent[0] ?? null);
        self::assertSame('playerbots exec Menelan !go travel Deputy Willem', $sent[1] ?? null);
        self::assertStringContainsString('Deputy Willem', $answer);
    }

    /**
     * The travel system only knows places its destination lists contain. Anywhere else still
     * has to work, and stopping a few paces short matters there: walking onto an NPC leaves the
     * companion inside them and can block the player clicking the person they asked for.
     */
    public function testAPlaceTheTravelSystemDoesNotKnowFallsBackToCoordinates(): void
    {
        $sent = [];
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent[] = $c;

            // The travel form is refused; the coordinate form is not.
            if (str_contains($c, 'go travel')) {
                throw new \App\World\Exception\WorldGatewayException('no such destination');
            }

            return 'ok';
        });

        // Bot at the origin, target 100 yards due north.
        $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]])->leadTheWay('Deputy Willem');

        self::assertMatchesRegularExpression('/!go 95\.00;0\.00;/', end($sent) ?: '');
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
     * @param array<int, array<string, mixed>> $creatures
     */
    private function tools(WorldGatewayInterface $world, array $creatures): CompanionTools
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
            $knowledge,
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, new PlayerbotCommandRunner($world)),
        );
    }
}
