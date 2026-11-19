<?php

declare(strict_types=1);

namespace App\Tests\Knowledge\Dbc;

use App\Knowledge\Dbc\AreaNames;
use App\Knowledge\Dbc\ZoneBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Reads the real WorldMapArea.dbc when one is available.
 *
 * Like the other DBC tests, this skips rather than fails without client data so a contributor
 * with no WoW install still gets a green suite.
 */
final class ZoneBoundsTest extends TestCase
{
    private function dbcDir(): string
    {
        $dir = $_ENV['ANM_DBC_DIR'] ?? $_SERVER['ANM_DBC_DIR'] ?? null;

        if (null === $dir || !is_readable($dir.'/WorldMapArea.dbc') || !is_readable($dir.'/AreaTable.dbc')) {
            self::markTestSkipped('Client DBC files not available; set ANM_DBC_DIR to the client data directory.');
        }

        return $dir;
    }

    private function zones(): ZoneBounds
    {
        return new ZoneBounds($this->dbcDir());
    }

    /**
     * Landmarks every 3.3.5a world has in the same place, so these are stable facts rather than
     * fixtures that drift. Coordinates are x, y in the server's own axes.
     */
    #[DataProvider('landmarks')]
    public function testItNamesTheZoneContainingAPoint(int $map, float $x, float $y, string $expected): void
    {
        $areaId = $this->zones()->areaAt($map, $x, $y);

        self::assertNotNull($areaId, 'no zone covers the point');
        // Named through AreaTable, not WorldMapArea: the latter labels Elwynn Forest "Elwynn".
        self::assertSame($expected, (new AreaNames($this->dbcDir()))->name($areaId));
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function landmarks(): iterable
    {
        // The night elf starting area, and the cave the Relics of Wakening sit in.
        yield 'Shadowglen' => [1, 10311.0, 832.0, 'Teldrassil'];
        // Orc starting area.
        yield 'Valley of Trials' => [1, -618.0, -4251.0, 'Durotar'];
        // Human starting area.
        yield 'Northshire' => [0, -8949.0, -132.0, 'Elwynn Forest'];
    }

    public function testAPointOutsideEveryZoneIsUnknownRatherThanGuessed(): void
    {
        // Far outside any world map box: saying nothing beats naming a neighbour.
        self::assertNull($this->zones()->areaAt(1, 500000.0, 500000.0));
    }

    public function testAnUnknownMapHasNoZones(): void
    {
        self::assertNull($this->zones()->areaAt(9999, 0.0, 0.0));
    }

    /**
     * Missing client data is a state to report, not one to remember. Caching it stored an empty
     * map, and every run after that knew no zones at all, even once ANM_DBC_DIR had been set.
     */
    public function testMissingClientDataIsNotCached(): void
    {
        $cache = new ArrayAdapter();

        self::assertNull((new ZoneBounds('/nonexistent/dbc', $cache))->areaAt(0, -9000.0, -100.0));
        self::assertSame([], $cache->getValues());
    }
}
