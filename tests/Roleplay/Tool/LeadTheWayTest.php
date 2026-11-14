<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\GameKnowledgeInterface;
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
     * Stop a few paces short. Walking onto an NPC leaves the companion standing inside them,
     * which looks wrong and can block the player clicking the very person they asked for.
     */
    public function testItStopsShortOfTheTarget(): void
    {
        $sent = null;
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('execute')->willReturnCallback(static function (string $c) use (&$sent): string {
            $sent = $c;

            return 'ok';
        });

        // Bot at the origin, target 100 yards due north.
        $answer = $this->tools($world, [['name' => 'Deputy Willem', 'level_min' => 5, 'level_max' => 5,
            'spawns' => 1, 'map' => 0, 'x' => 100.0, 'y' => 0.0]])->leadTheWay('Deputy Willem');

        self::assertNotNull($sent);
        self::assertMatchesRegularExpression('/^playerbots exec Menelan !go 95\.00;0\.00;/', $sent);
        self::assertStringContainsString('Deputy Willem', $answer);
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
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, new PlayerbotCommandRunner($world)),
        );
    }
}
