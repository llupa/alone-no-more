<?php

declare(strict_types=1);

namespace App\Tests\Herding;

use App\Director\CompanionRequests;
use App\Director\CompanionsOnCall;
use App\Herding\BotRoster;
use App\Herding\BotStagerInterface;
use App\Herding\HerdingWatcher;
use App\Herding\StagingResult;
use App\Roleplay\Memory\ConversationStoreInterface;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\Gender;
use App\World\Model\Position;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The watcher's job is mostly to do nothing. A zone boundary is a line on a map, not an
 * intention — ride along one, or step into a cave that counts as its own zone, and a watcher
 * without patience tears the world down and rebuilds it every few seconds.
 */
final class HerdingWatcherTest extends TestCase
{
    private BotStagerInterface&MockObject $stager;

    protected function setUp(): void
    {
        $this->stager = $this->createMock(BotStagerInterface::class);
        $this->stager->method('dismissDistant')->willReturn([]);
    }

    private function watcher(int $settle = 60, int $refresh = 180): HerdingWatcher
    {
        $conversations = $this->createStub(ConversationStoreInterface::class);
        $conversations->method('recentlyActiveBots')->willReturn([]);

        return new HerdingWatcher($this->stager, $conversations, $this->nobodyOnCall(), $settle, $refresh);
    }

    /**
     * Nobody has asked for a companion by name, which is the ordinary case. Built from real
     * collaborators over stub connections: these paths never reach the database, and that beats
     * widening production code to suit a test.
     */
    private function nobodyOnCall(): CompanionsOnCall
    {
        $connection = $this->createStub(Connection::class);

        return new CompanionsOnCall(
            new CompanionRequests(sys_get_temp_dir().'/anm-oncall-'.bin2hex(random_bytes(6)).'.sqlite'),
            $this->createStub(WorldGatewayInterface::class),
            new BotRoster($connection),
            new BotSpeechDispatcher($connection),
        );
    }

    private static function playerIn(int $zoneId): Actor
    {
        return new Actor(
            guid: 100,
            name: 'Doppel',
            race: CharacterRace::NightElf,
            class: CharacterClass::Rogue,
            gender: Gender::Male,
            level: 10,
            position: new Position(0, 0, 0, 1),
            zoneId: $zoneId,
            isBot: false,
            isOnline: true,
        );
    }

    private function expectStagings(int $times): void
    {
        $this->stager->expects(self::exactly($times))
            ->method('stage')
            ->willReturn(new StagingResult(0, 50, [['name' => 'Bot', 'level' => 10]], []));
    }

    /**
     * A fresh listener should populate at once rather than leaving the player alone for a minute.
     */
    public function testItStagesImmediatelyOnFirstSighting(): void
    {
        $this->expectStagings(1);

        self::assertNotNull($this->watcher()->tick(self::playerIn(141)));
    }

    public function testStayingPutDoesNothing(): void
    {
        $this->expectStagings(1); // only the first sighting

        $watcher = $this->watcher();
        $watcher->tick(self::playerIn(141));

        self::assertNull($watcher->tick(self::playerIn(141)));
        self::assertNull($watcher->tick(self::playerIn(141)));
    }

    /**
     * The point of the settle window: crossing a border does not immediately rebuild the world.
     */
    public function testANewZoneIsNotBelievedImmediately(): void
    {
        $this->expectStagings(1);

        $watcher = $this->watcher(settle: 60);
        $watcher->tick(self::playerIn(141));

        self::assertNull($watcher->tick(self::playerIn(331)), 'should wait before acting');
    }

    /**
     * Riding along a boundary, or dipping into a cave and back. Nothing should happen at all.
     */
    public function testFlickeringAcrossABorderIsIgnored(): void
    {
        $this->expectStagings(1);

        $watcher = $this->watcher(settle: 60);
        $watcher->tick(self::playerIn(141));

        foreach ([331, 141, 331, 141, 331, 141] as $zone) {
            self::assertNull($watcher->tick(self::playerIn($zone)));
        }
    }

    /**
     * Arrival always takes two observations: the first records where they have gone, the second
     * confirms they are still there. Even with no settle window at all, one sighting is never
     * enough — a single poll cannot tell passing through from arriving.
     */
    public function testADestinationThatHoldsIsEventuallyBelieved(): void
    {
        $this->expectStagings(2); // first sighting, then the confirmed move

        $watcher = $this->watcher(settle: 0);
        $watcher->tick(self::playerIn(141));

        self::assertNull($watcher->tick(self::playerIn(331)), 'the first sighting only starts the clock');
        self::assertNotNull($watcher->tick(self::playerIn(331)), 'the second confirms it');
    }

    /**
     * Logging out makes whatever we knew about their whereabouts stale, so the next sighting
     * starts over rather than assuming they are where they were.
     */
    public function testResetMakesTheNextSightingFresh(): void
    {
        $this->expectStagings(2);

        $watcher = $this->watcher();
        $watcher->tick(self::playerIn(141));
        $watcher->reset();

        self::assertNotNull($watcher->tick(self::playerIn(141)));
    }

    public function testItTopsUpTheCrowdPeriodically(): void
    {
        $this->expectStagings(2); // first sighting, then the top-up

        $watcher = $this->watcher(refresh: 0);
        $watcher->tick(self::playerIn(141));

        self::assertNotNull($watcher->tick(self::playerIn(141)), 'a zero refresh window tops up every tick');
    }
}
