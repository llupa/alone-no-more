<?php

declare(strict_types=1);

namespace App\Tests\Replay;

use App\Replay\ReplayGroupRepository;
use App\Replay\Snapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The recorded party has to answer the way the live one does, or replay quietly shows something
 * no server would: the interface promises everyone else in the group, and the replay used to
 * hand back the whole group — so anm:party listed the player as a member of their own party.
 */
#[CoversClass(ReplayGroupRepository::class)]
final class ReplayGroupRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'anm-groups-');
        // The capture writes each group leader first.
        file_put_contents($this->path, json_encode(['groups' => ['7' => [10, 20, 30]]], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testMembersAreEveryoneElseInTheGroup(): void
    {
        $groups = new ReplayGroupRepository(new Snapshot($this->path));

        self::assertSame([10, 30], $groups->memberGuidsWith(20));
        self::assertSame([20, 30], $groups->memberGuidsWith(10));
    }

    public function testTheLeaderIsStillTheFirstOneCaptured(): void
    {
        $groups = new ReplayGroupRepository(new Snapshot($this->path));

        self::assertSame(10, $groups->leaderGuid(30));
        self::assertSame(10, $groups->leaderGuid(10), 'the leader leads their own group too');
    }

    public function testSomebodyInNoGroupHasNobody(): void
    {
        $groups = new ReplayGroupRepository(new Snapshot($this->path));

        self::assertSame([], $groups->memberGuidsWith(99));
        self::assertFalse($groups->isGrouped(99));
        self::assertNull($groups->leaderGuid(99));
    }
}
