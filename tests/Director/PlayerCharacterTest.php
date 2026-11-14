<?php

declare(strict_types=1);

namespace App\Tests\Director;

use App\Config\Settings;
use App\Director\PlayerCharacter;
use App\Tests\Roleplay\ActorMother;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Working out who is actually playing.
 *
 * Written after an evening in which the director followed a character who was not logged in and
 * said nothing about it. A stale name must never be able to do that again.
 */
#[CoversClass(PlayerCharacter::class)]
final class PlayerCharacterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anm-player-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @param Actor[] $online
     */
    private function detector(array $online, ?string $pinned = null): PlayerCharacter
    {
        $world = $this->createStub(WorldGatewayInterface::class);
        $world->method('onlinePlayers')->willReturn($online);

        $settings = new Settings($this->path);

        if (null !== $pinned) {
            $settings->follow($pinned);
        }

        return new PlayerCharacter($world, $settings);
    }

    /**
     * The ordinary case: one person playing alone, nothing configured.
     */
    public function testTheOnlyCharacterOnlineIsThePlayer(): void
    {
        $player = $this->detector([ActorMother::player(100, 'Klemprime')])->current();

        self::assertNotNull($player);
        self::assertSame('Klemprime', $player->name);
    }

    public function testNobodyOnlineIsNull(): void
    {
        self::assertNull($this->detector([])->current());
    }

    public function testAPinnedCharacterWinsWhenTheyArePlaying(): void
    {
        $player = $this->detector(
            [ActorMother::player(100, 'Doppel'), ActorMother::player(101, 'Klemprime')],
            pinned: 'Klemprime',
        )->current();

        self::assertNotNull($player);
        self::assertSame('Klemprime', $player->name);
    }

    /**
     * The failure this class exists to prevent: a pin naming someone who is not logged in used
     * to mean the director followed nobody at all, in silence.
     */
    public function testAStalePinFallsBackToWhoeverIsPlaying(): void
    {
        $detector = $this->detector([ActorMother::player(101, 'Klemprime')], pinned: 'Doppel');

        $player = $detector->current();

        self::assertNotNull($player);
        self::assertSame('Klemprime', $player->name);
        self::assertSame('Doppel is not online', $detector->pinIsStale());
    }

    public function testAPinThatIsBeingPlayedIsNotStale(): void
    {
        self::assertNull(
            $this->detector([ActorMother::player(100, 'Doppel')], pinned: 'Doppel')->pinIsStale(),
        );
    }

    public function testWithoutAPinEverybodyOnlineIsFollowed(): void
    {
        $following = $this->detector([
            ActorMother::player(100, 'Doppel'),
            ActorMother::player(101, 'Klemprime'),
        ])->toFollow();

        self::assertCount(2, $following);
    }

    public function testAPinNarrowsFollowingToThatCharacter(): void
    {
        $following = $this->detector([
            ActorMother::player(100, 'Doppel'),
            ActorMother::player(101, 'Klemprime'),
        ], pinned: 'Doppel')->toFollow();

        self::assertCount(1, $following);
        self::assertSame('Doppel', $following[0]->name);
    }
}
