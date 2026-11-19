<?php

declare(strict_types=1);

namespace App\Tests\Replay;

use App\Replay\ReplayKnowledge;
use App\Replay\Snapshot;
use App\World\Model\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where to walk to, answered from a recording.
 *
 * Replay has to give the same kind of answer the live server does, or a feature that works in
 * one breaks in the other: the nearest single spawn on the companion's own map, never an
 * average of them all.
 */
#[CoversClass(ReplayKnowledge::class)]
final class ReplayKnowledgeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'anm-snapshot-');

        $creature = static fn (string $name, int $map, float $x, float $y): array => [
            'name' => $name, 'level_min' => 14, 'level_max' => 15, 'map' => $map, 'x' => $x, 'y' => $y, 'z' => 40.0,
        ];

        file_put_contents($this->path, json_encode(['creatures' => [
            $creature('Defias Pillager', 36, 10.0, 10.0),
            $creature('Defias Pillager', 0, 900.0, 0.0),
            $creature('Defias Pillager Captain', 0, 5.0, 0.0),
            $creature('Defias Pillager', 0, 100.0, 0.0),
        ]], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testTheNearestSpawnOnTheSameMapWins(): void
    {
        $spawn = (new ReplayKnowledge(new Snapshot($this->path)))
            ->nearestSpawn('Defias Pillager', new Position(0.0, 0.0, 40.0, 0));

        self::assertNotNull($spawn);
        self::assertSame('Defias Pillager', $spawn['name'], 'the exact name, not a longer one that is closer');
        self::assertSame(0, $spawn['map'], 'not the one in the instance');
        self::assertSame(100.0, $spawn['x'], 'the near spawn, not the far one');
    }

    public function testNothingOnThisMapIsNull(): void
    {
        $spawn = (new ReplayKnowledge(new Snapshot($this->path)))
            ->nearestSpawn('Defias Pillager', new Position(0.0, 0.0, 40.0, 1));

        self::assertNull($spawn);
    }
}
