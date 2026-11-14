<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Tool;

use App\Knowledge\BagsInterface;
use App\Knowledge\Dbc\ZoneBounds;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\LevelProgressInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Tool\BotCommandContext;
use App\Roleplay\Tool\CompanionTools;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\Roleplay\Travel\Journeys;
use App\Roleplay\Travel\LeadOrders;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * How a companion talks about what someone is doing.
 *
 * Written from a real failure: asked "I have 2 already" about six Bloodfeather Belts, a bot could
 * only guess at what was meant, because nothing told it the player's progress. The numbers are
 * the point here — "two of six" is the answer, "you have some belts" is not.
 */
final class CompanionQuestLogTest extends TestCase
{
    private const BOT = 1;
    private const PLAYER = 100;

    public function testItSaysHowFarAlongTheyAre(): void
    {
        $answer = $this->tools([
            $this->quest('The Enchanted Glade', 11, needs: [['what' => 'Bloodfeather Belt', 'have' => 2, 'need' => 6]]),
        ])->checkTheirQuestLog();

        self::assertStringContainsString('Doppel is working on', $answer);
        self::assertStringContainsString('Bloodfeather Belt 2 of 6', $answer);
    }

    public function testAFinishedQuestIsReportedAsReadyToHandIn(): void
    {
        $answer = $this->tools([
            $this->quest('Crown of the Earth', 11, complete: true, needs: [
                ['what' => 'Filled Amethyst Phial', 'have' => 1, 'need' => 1],
            ]),
        ])->checkTheirQuestLog();

        self::assertStringContainsString('waiting to be handed to Marshal McBride', $answer);
        // A finished quest must not also be described as needing anything.
        self::assertStringNotContainsString('Filled Amethyst Phial', $answer);
    }

    /**
     * Requirements already met are not "outstanding". A bot listing them would tell a player to
     * go and fetch something sitting in their bag.
     */
    public function testRequirementsAlreadyMetAreNotListed(): void
    {
        $answer = $this->tools([
            $this->quest('The Relics of Wakening', 9, needs: [
                ['what' => 'Raven Claw Talisman', 'have' => 1, 'need' => 1],
                ['what' => 'Sapphire of Sky', 'have' => 0, 'need' => 1],
            ]),
        ])->checkTheirQuestLog();

        self::assertStringNotContainsString('Raven Claw Talisman', $answer);
        self::assertStringContainsString('Sapphire of Sky 0 of 1', $answer);
    }

    public function testAnEmptyLogIsSaidPlainly(): void
    {
        self::assertSame(
            'Doppel is not on any quests at the moment.',
            $this->tools([])->checkTheirQuestLog(),
        );
    }

    public function testTheBotCanReadItsOwnLogToo(): void
    {
        $answer = $this->tools([$this->quest('The Shimmering Frond', 10, complete: true)])->checkMyQuestLog();

        self::assertStringContainsString('You are working on', $answer);
        self::assertStringContainsString('The Shimmering Frond', $answer);
    }

    /**
     * @param array<int, array{what: string, have: int, need: int}> $needs
     *
     * @return array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}
     */
    private function quest(string $title, int $level, bool $complete = false, array $needs = []): array
    {
        return ['id' => 1, 'title' => $title, 'level' => $level, 'complete' => $complete, 'needs' => $needs];
    }

    /**
     * @param array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}> $quests
     */
    private function tools(array $quests): CompanionTools
    {
        $log = $this->createStub(QuestLogInterface::class);
        $log->method('activeFor')->willReturn($quests);

        $context = new BotCommandContext();
        $context->enter(ActorMother::bot(self::BOT, 'Menelan'), ActorMother::player(self::PLAYER), null);

        // The runner is final and these paths never reach it; a real one over a stub gateway
        // is simpler than widening the production code to suit a test.
        $runner = new PlayerbotCommandRunner($this->createStub(WorldGatewayInterface::class));

        // The dispatcher is final and the quest-log paths never reach it; a real one over a
        // stub connection beats widening production code to suit a test.
        $knowledge = $this->createStub(GameKnowledgeInterface::class);
        $knowledge->method('questGivers')->willReturn(['starts' => ['Deputy Willem'], 'ends' => ['Marshal McBride']]);

        return new CompanionTools(
            $context,
            $runner,
            $log,
            $this->createStub(BagsInterface::class),
            $knowledge,
            $this->createStub(LevelProgressInterface::class),
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($this->createStub(WorldGatewayInterface::class), $runner),
            new LeadOrders(new Outbox($this->createStub(Connection::class))),
            new ZoneBounds('/nonexistent'),
        );
    }
}
