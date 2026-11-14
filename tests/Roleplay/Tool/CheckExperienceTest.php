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
 * "How much more experience do you need?".
 *
 * From play: a companion answered that you could look at the experience bar — reasonable about
 * the player's own, and no answer at all about its own, because it had no way to know.
 */
final class CheckExperienceTest extends TestCase
{
    private const BOT = 1;
    private const PLAYER = 100;

    public function testACompanionKnowsHowFarAlongItIs(): void
    {
        $answer = $this->tools([self::BOT => [
            'level' => 3, 'xp' => 507, 'needed' => 1400, 'remaining' => 893, 'percent' => 36,
        ]])->checkExperience('mine');

        self::assertStringContainsString('You are level 3', $answer);
        self::assertStringContainsString('36%', $answer);
        // Thousands separated: a companion speaking aloud does not read out "893" as digits
        // any more than it reads out a database row.
        self::assertStringContainsString('893', $answer);
    }

    public function testItCanAnswerAboutThePlayerToo(): void
    {
        $answer = $this->tools([self::PLAYER => [
            'level' => 5, 'xp' => 716, 'needed' => 2800, 'remaining' => 2084, 'percent' => 26,
        ]])->checkExperience('theirs');

        self::assertStringContainsString('Doppel is level 5', $answer);
        self::assertStringContainsString('2,084', $answer);
    }

    /**
     * At the level cap there is nothing left to earn, and "0 of 0" would be worse than saying
     * so plainly.
     */
    public function testNothingKnownIsSaidPlainly(): void
    {
        self::assertStringContainsString('cannot tell', $this->tools([])->checkExperience('mine'));
    }

    /**
     * @param array<int, array{level: int, xp: int, needed: int, remaining: int, percent: int}> $progress
     */
    private function tools(array $progress): CompanionTools
    {
        $levels = $this->createStub(LevelProgressInterface::class);
        $levels->method('forCharacter')->willReturnCallback(
            static fn (int $guid): ?array => $progress[$guid] ?? null,
        );

        $context = new BotCommandContext();
        $context->enter(ActorMother::bot(self::BOT, 'Anduril'), ActorMother::player(self::PLAYER), null);

        $world = $this->createStub(WorldGatewayInterface::class);
        $runner = new PlayerbotCommandRunner($world);

        return new CompanionTools(
            $context,
            $runner,
            $this->createStub(QuestLogInterface::class),
            $this->createStub(BagsInterface::class),
            $this->createStub(GameKnowledgeInterface::class),
            $levels,
            new BotSpeechDispatcher($this->createStub(Connection::class)),
            new ErrandBook(),
            new Journeys($world, $runner),
            new LeadOrders(new Outbox($this->createStub(Connection::class))),
            new ZoneBounds('/nonexistent'),
        );
    }
}
