<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Herding\BotStagerInterface;
use App\Herding\StagingResult;
use App\Mcp\WorldDirector;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Tests\Roleplay\ActorMother;
use App\World\WorldGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The tools that change the world, called by a client we do not control.
 *
 * An MCP client invents arguments from a description, so the guards matter more here than in our
 * own code: an assistant will cheerfully pass a verb that sounds plausible and does not exist.
 */
#[CoversClass(WorldDirector::class)]
final class WorldDirectorTest extends TestCase
{
    public function testItPopulatesTheAreaAroundACharacter(): void
    {
        $stager = $this->createMock(BotStagerInterface::class);
        $stager->expects(self::once())
            ->method('stage')
            ->willReturn(new StagingResult(alreadyPresent: 12, target: 50, broughtIn: ['A', 'B'], sentAway: []));

        $answer = $this->director($this->gatewayFinding(online: true), $stager)->summonCrowd('Doppel', 50);

        self::assertStringContainsString('12', $answer);
    }

    public function testAnUnknownCharacterIsSaidPlainlyRatherThanThrown(): void
    {
        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn(null);

        $stager = $this->createMock(BotStagerInterface::class);
        $stager->expects(self::never())->method('stage');

        self::assertStringContainsString(
            'No character called "Nobody"',
            $this->director($world, $stager)->summonCrowd('Nobody'),
        );
    }

    /**
     * Staging puts bots where the player is standing. With nobody standing anywhere, the saved
     * position is wherever they logged out, and a crowd would appear around a ghost.
     */
    public function testNobodyIsGatheredAroundAnOfflineCharacter(): void
    {
        $stager = $this->createMock(BotStagerInterface::class);
        $stager->expects(self::never())->method('stage');

        $answer = $this->director($this->gatewayFinding(online: false), $stager)->summonCrowd('Doppel');

        self::assertStringContainsString('offline', $answer);
    }

    public function testAnInventedOrderIsRefusedBeforeItReachesTheServer(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn(ActorMother::bot(1, 'Menelan'));
        $world->expects(self::never())->method('execute');

        $answer = $this->director($world, $this->createStub(BotStagerInterface::class))
            ->botCommand('Menelan', 'perform a heroic charge');

        self::assertStringContainsString('is not a playerbots command', $answer);
    }

    public function testARealOrderIsSentToTheNamedBot(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn(ActorMother::bot(1, 'Menelan'));
        $world->expects(self::once())
            ->method('execute')
            ->with('playerbots exec Menelan !follow')
            ->willReturn("Menelan: 'follow' sent");

        $answer = $this->director($world, $this->createStub(BotStagerInterface::class))
            ->botCommand('Menelan', 'follow');

        self::assertSame("Menelan: 'follow' sent", $answer);
    }

    /**
     * `playerbots exec` runs on a GM console, which can create accounts and ban players. An
     * assistant holding this tool also reads player-written chat through recent_chat, so the
     * name it passes is not necessarily a name it chose for itself.
     */
    public function testANameThatCouldSmuggleASecondConsoleCommandIsRefused(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn(null);
        $world->expects(self::never())->method('execute');

        $answer = $this->director($world, $this->createStub(BotStagerInterface::class))
            ->botCommand("Menelan\naccount create hax hax", 'follow');

        self::assertStringContainsString('no bot called', $answer);
    }

    /**
     * Orders may only be given to bots. A real player is not ours to move about.
     */
    public function testAPlayerCannotBeOrderedAbout(): void
    {
        $world = $this->createMock(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn(ActorMother::player(100, 'Doppel'));
        $world->expects(self::never())->method('execute');

        $answer = $this->director($world, $this->createStub(BotStagerInterface::class))
            ->botCommand('Doppel', 'follow');

        self::assertStringContainsString('no bot called', $answer);
    }

    public function testThinningACrowdReportsWhoWent(): void
    {
        $stager = $this->createStub(BotStagerInterface::class);
        $stager->method('dismissDistant')->willReturn(['Erion', 'Katkan']);

        $answer = $this->director($this->gatewayFinding(online: true), $stager)->sendBotsAway('Doppel');

        self::assertStringContainsString('Erion, Katkan', $answer);
    }

    private function gatewayFinding(bool $online): WorldGatewayInterface
    {
        $actor = ActorMother::player(100, 'Doppel');

        if (!$online) {
            $actor = new \App\World\Model\Actor(
                guid: $actor->guid,
                name: $actor->name,
                race: $actor->race,
                class: $actor->class,
                gender: $actor->gender,
                level: $actor->level,
                position: $actor->position,
                zoneId: $actor->zoneId,
                isBot: false,
                isOnline: false,
            );
        }

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActor')->willReturn($actor);

        return $world;
    }

    private function director(WorldGatewayInterface $world, BotStagerInterface $stager): WorldDirector
    {
        return new WorldDirector($world, $stager, new PlayerbotCommandRunner($world));
    }
}
