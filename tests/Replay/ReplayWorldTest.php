<?php

declare(strict_types=1);

namespace App\Tests\Replay;

use App\Kernel;
use App\Knowledge\Dbc\AreaNames;
use App\Mcp\WorldInspector;
use App\Replay\ReplayChatEvents;
use App\Replay\ReplayGateway;
use App\Replay\ReplayGroupRepository;
use App\Replay\ReplayKnowledge;
use App\Replay\ReplayQuestLog;
use App\Replay\Snapshot;
use PHPUnit\Framework\TestCase;

/**
 * The experience of someone who has just cloned the repository.
 *
 * They have no AzerothCore, no MySQL, no compiled C++ module and no copy of a 2008 game client.
 * If replay does not answer for them, the "clone and run it in five minutes" promise in the
 * README is a lie, and nothing else in the suite would notice — every other test either stubs
 * its collaborators or skips without client data.
 *
 * So this uses the committed fixture and the real classes, with the DBC directory pointed
 * somewhere that does not exist, which is exactly the situation a stranger is in.
 */
final class ReplayWorldTest extends TestCase
{
    private const NO_CLIENT_DATA = '/nonexistent-client-data';

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function snapshot(): Snapshot
    {
        $snapshot = new Snapshot(self::projectDir().'/fixtures/world.json');

        if (!$snapshot->exists()) {
            self::fail('fixtures/world.json is missing — the clone-and-run path is broken. Capture one with `bin/console anm:snapshot`.');
        }

        return $snapshot;
    }

    private function inspector(): WorldInspector
    {
        $snapshot = $this->snapshot();

        return new WorldInspector(
            new ReplayGateway($snapshot),
            new ReplayKnowledge($snapshot),
            new ReplayQuestLog($snapshot),
            new ReplayGroupRepository($snapshot),
            // No client data, so zone names come from what the snapshot captured.
            new AreaNames(self::NO_CLIENT_DATA, null, self::projectDir().'/fixtures/areas.json'),
        );
    }

    /**
     * Compiling the replay container is the wiring test: every alias must resolve to something
     * that does not need a database.
     */
    public function testTheReplayEnvironmentBuilds(): void
    {
        $kernel = new Kernel('replay', false);
        $kernel->boot();

        self::assertSame('replay', $kernel->getEnvironment());

        $kernel->shutdown();
    }

    public function testACharacterIsDescribedFromTheSnapshot(): void
    {
        $state = $this->inspector()->playerState('Klemprime');

        self::assertStringContainsString('Klemprime is a level', $state);
        self::assertStringContainsString('Redridge Mountains', $state, 'zone names must survive having no client data');
    }

    public function testAnUnknownCharacterIsReportedRatherThanInvented(): void
    {
        self::assertStringContainsString('No character called "Arthas"', $this->inspector()->playerState('Arthas'));
    }

    public function testNearbyActorsAndLivingCreaturesComeFromTheSnapshot(): void
    {
        $nearby = $this->inspector()->nearbyActors('Klemprime', 120);

        self::assertStringContainsString('Within 120 yards', $nearby);
        self::assertStringContainsString('Creatures alive nearby', $nearby);
    }

    public function testThePopulationOfEachZoneIsCounted(): void
    {
        self::assertStringContainsString('Redridge Mountains', $this->inspector()->zonePopulation());
    }

    public function testTheRecordedConversationIsReadable(): void
    {
        $chat = new ReplayChatEvents($this->snapshot());

        self::assertNotSame(0, $chat->latestId());
        self::assertNotEmpty($chat->since(0, 5));
    }

    /**
     * The archive answers for *this* server's data, so the lookups over a snapshot must behave
     * the way they do live — including reporting a chain that reuses one title.
     */
    public function testTheArchiveAnswersFromTheSnapshot(): void
    {
        $quests = (new ReplayKnowledge($this->snapshot()))->findQuestsByName('Dry Times');

        self::assertNotEmpty($quests);
        self::assertSame('Dry Times', $quests[0]['title']);
    }

    /**
     * There is no server to carry an order out. Saying so beats a demo that silently swallows
     * commands and looks like it worked.
     */
    public function testOrdersAreRefusedHonestly(): void
    {
        self::assertStringContainsString(
            'recorded world',
            (new ReplayGateway($this->snapshot()))->execute('server info'),
        );
    }
}
