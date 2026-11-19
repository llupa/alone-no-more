<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Errand;

use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\Errand;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Errand\ErrandRunner;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Working through a job, and knowing when it is finished.
 *
 * "Attack until you have seven" produced one wolf and silence, because attack is a single order.
 * What was missing was a goal with a stopping condition — and the stopping has to come from the
 * server's counters, since a model asked to keep a tally will promise to and then cannot.
 */
#[CoversClass(ErrandRunner::class)]
#[CoversClass(ErrandBook::class)]
final class ErrandRunnerTest extends TestCase
{
    private const BOT = 1;

    /**
     * @param array<int, array<string, mixed>> $questLog
     */
    private function runner(ErrandBook $book, array $questLog = [], ?Connection $connection = null): ErrandRunner
    {
        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(ActorMother::bot(self::BOT, 'Jaelae'));

        $log = $this->createStub(QuestLogInterface::class);
        $log->method('activeFor')->willReturn($questLog);

        return new ErrandRunner(
            $book,
            new BotSpeechDispatcher(new Outbox($connection ?? $this->createStub(Connection::class))),
            $log,
            $world,
            swingSeconds: 0,
        );
    }

    /**
     * @return array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}
     */
    private function quest(int $id, string $title, bool $complete, int $have = 0, int $need = 8): array
    {
        return [
            'id' => $id, 'title' => $title, 'level' => 5, 'complete' => $complete,
            'needs' => [['what' => 'Diseased Wolf Pelt', 'have' => $have, 'need' => $need]],
        ];
    }

    public function testASwingIsSentWhileTheWorkIsOutstanding(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with('anm_outbox', self::anything());

        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'wolf', questId: 9, questTitle: 'Wolves Across the Border'));

        $finished = $this->runner($book, [$this->quest(9, 'Wolves Across the Border', complete: false, have: 2)], $connection)->tick();

        self::assertSame([], $finished);
        self::assertNotNull($book->of(self::BOT), 'the errand should still be open');
    }

    /**
     * The server counts kills and drops. That is the whole reason a quest is the stopping
     * condition rather than the companion's own arithmetic.
     */
    public function testTheErrandEndsWhenTheQuestIsSatisfied(): void
    {
        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'wolf', questId: 9, questTitle: 'Wolves Across the Border'));

        $finished = $this->runner($book, [$this->quest(9, 'Wolves Across the Border', complete: true, have: 8)])->tick();

        self::assertNull($book->of(self::BOT));
        self::assertStringContainsString('is finished', $finished[self::BOT] ?? '');
    }

    public function testHandingTheQuestInWhileWorkingAlsoEndsIt(): void
    {
        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'wolf', questId: 9, questTitle: 'Wolves Across the Border'));

        // Gone from the log entirely.
        $this->runner($book, [])->tick();

        self::assertNull($book->of(self::BOT));
    }

    /**
     * Without a quest to finish against there has to be a stop of last resort, or a misheard
     * word becomes a companion grinding all evening.
     */
    public function testAnOpenEndedErrandStopsEventually(): void
    {
        $book = new ErrandBook();
        $errand = new Errand(self::BOT, 'wolf', maxSwings: 2);
        $book->start($errand);

        $runner = $this->runner($book);
        $runner->tick();
        $runner->tick();
        $finished = $runner->tick();

        self::assertNull($book->of(self::BOT));
        self::assertStringContainsString('enough for now', $finished[self::BOT] ?? '');
    }

    /**
     * A fight takes a few seconds; re-issuing every tick would have the companion abandon a
     * half-killed wolf to run at a fresh one.
     */
    public function testACompanionIsNotInterruptedMidFight(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'wolf'));

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(ActorMother::bot(self::BOT, 'Jaelae'));
        $log = $this->createStub(QuestLogInterface::class);
        $log->method('activeFor')->willReturn([]);

        $runner = new ErrandRunner($book, new BotSpeechDispatcher(new Outbox($connection)), $log, $world, swingSeconds: 60);
        $runner->tick();
        $runner->tick();
    }

    public function testAnErrandDiesWithTheBotLoggingOut(): void
    {
        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'wolf'));

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(null);
        $log = $this->createStub(QuestLogInterface::class);
        $log->method('activeFor')->willReturn([]);

        (new ErrandRunner($book, new BotSpeechDispatcher(new Outbox($this->createStub(Connection::class))), $log, $world))->tick();

        self::assertTrue($book->isEmpty());
    }

    /**
     * "Help me collect mine, I need six more." Captured from a real session, where the companion
     * had already finished its own copy of the quest: it agreed to help, checked its own log on
     * the next tick, found nothing outstanding and stopped. From the player's side that is the
     * bot ignoring them.
     */
    public function testAnErrandCanServeThePlayersQuestRatherThanTheBots(): void
    {
        $player = 100;
        $book = new ErrandBook();
        $book->start(new Errand(self::BOT, 'thug', questId: 9, questTitle: 'Brotherhood of Thieves', questOwnerGuid: $player));

        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('findActorByGuid')->willReturn(ActorMother::bot(self::BOT, 'Ilyelanea'));

        // The companion is done; the player is not. The player's log is the one that decides.
        $log = $this->createMock(QuestLogInterface::class);
        $log->method('activeFor')->willReturnCallback(
            fn (int $guid): array => $guid === $player
                ? [$this->quest(9, 'Brotherhood of Thieves', complete: false, have: 2)]
                : [$this->quest(9, 'Brotherhood of Thieves', complete: true, have: 8)],
        );

        $finished = (new ErrandRunner($book, new BotSpeechDispatcher(new Outbox($this->createStub(Connection::class))), $log, $world, swingSeconds: 0))->tick();

        self::assertSame([], $finished, 'the errand must outlive the companion finishing its own copy');
        self::assertNotNull($book->of(self::BOT));
    }

    public function testProgressComesFromTheServerNotFromCounting(): void
    {
        $book = new ErrandBook();
        $errand = new Errand(self::BOT, 'wolf', questId: 9, questTitle: 'Wolves Across the Border');
        $book->start($errand);

        $progress = $this->runner($book, [$this->quest(9, 'Wolves Across the Border', complete: false, have: 3)])
            ->progressOf($errand);

        self::assertSame('Diseased Wolf Pelt 3 of 8', $progress);
    }
}
