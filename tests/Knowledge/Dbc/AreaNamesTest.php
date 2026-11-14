<?php

declare(strict_types=1);

namespace App\Tests\Knowledge\Dbc;

use App\Knowledge\Dbc\AreaNames;
use App\Knowledge\Dbc\DbcException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads the real AreaTable.dbc when one is available.
 *
 * DBC files are client data and cannot be committed, so these skip rather than fail when the
 * data directory is absent — a contributor without a WoW install should still get a green suite.
 */
final class AreaNamesTest extends TestCase
{
    private function areaNames(): AreaNames
    {
        $dir = $_ENV['ANM_DBC_DIR'] ?? $_SERVER['ANM_DBC_DIR'] ?? null;

        if (null === $dir || !is_readable($dir.'/AreaTable.dbc')) {
            self::markTestSkipped('AreaTable.dbc not available; set ANM_DBC_DIR to the client data directory.');
        }

        return new AreaNames($dir);
    }

    /**
     * Ids and names a 3.3.5a client has always had, so these are stable facts rather than
     * fixtures that drift.
     */
    #[DataProvider('knownAreas')]
    public function testItNamesKnownAreas(int $areaId, string $expected): void
    {
        self::assertSame($expected, $this->areaNames()->name($areaId));
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function knownAreas(): iterable
    {
        yield 'a zone' => [141, 'Teldrassil'];
        yield 'another zone' => [12, 'Elwynn Forest'];
        yield 'a city' => [1519, 'Stormwind City'];
        yield 'a subzone' => [188, 'Shadowglen'];
        yield 'multi-word zone' => [406, 'Stonetalon Mountains'];
    }

    /**
     * The reason this class exists: the server knows only numbers, and a bot that can say
     * "Shadowglen" instead of "Teldrassil" sounds like it is actually standing somewhere.
     */
    public function testASubzoneResolvesToItsParentZone(): void
    {
        self::assertSame('Teldrassil', $this->areaNames()->zoneNameOf(188));
    }

    public function testAZoneIsItsOwnZone(): void
    {
        self::assertSame('Teldrassil', $this->areaNames()->zoneNameOf(141));
    }

    public function testUnknownAreasAreNullRatherThanInvented(): void
    {
        self::assertNull($this->areaNames()->name(999999));
        self::assertNull($this->areaNames()->zoneNameOf(999999));
    }

    public function testItExplainsItselfWhenThereIsNeitherClientDataNorASnapshot(): void
    {
        $this->expectException(DbcException::class);
        $this->expectExceptionMessageMatches('/client data|README/');

        (new AreaNames('/nonexistent/dbc'))->name(141);
    }

    /**
     * A clone has no client data at all, so zone names come from whatever the snapshot
     * captured. Without this the replay demo calls Teldrassil "an unnamed place".
     */
    public function testItFallsBackToACapturedSnapshotWhenClientDataIsAbsent(): void
    {
        $fixture = \dirname(__DIR__, 3).'/fixtures/areas.json';

        if (!is_readable($fixture)) {
            self::markTestSkipped('No captured areas fixture.');
        }

        self::assertSame('Teldrassil', (new AreaNames('/nonexistent/dbc', null, $fixture))->name(141));
    }
}
