<?php

declare(strict_types=1);

namespace App\Tests\World\Model;

use App\World\Exception\WorldGatewayException;
use App\World\Model\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PositionTest extends TestCase
{
    /**
     * Captured verbatim from the running server, so the parser is tested against the
     * real wire format rather than one we imagined.
     */
    public static function realServerLines(): iterable
    {
        yield 'bot in Tirisfal' => [
            '1676.71 1678.31 121.67 0 2.70526 |Tirisfal Glades|',
            1676.71, 1678.31, 121.67, 0, 2.70526, 'Tirisfal Glades',
        ];
        yield 'negative coordinates' => [
            '-9464.75 62.17 56 0 0.6 |Elwynn Forest|',
            -9464.75, 62.17, 56.0, 0, 0.6, 'Elwynn Forest',
        ];
        yield 'northrend map id' => [
            '5633.14 699.496 652.042 571 0 |Dalaran|',
            5633.14, 699.496, 652.042, 571, 0.0, 'Dalaran',
        ];
        yield 'negative orientation' => [
            '8393.06 -392.833 903.119 571 -2.3028 |The Storm Peaks|',
            8393.06, -392.833, 903.119, 571, -2.3028, 'The Storm Peaks',
        ];
        yield 'multi-word zone' => [
            '897.845 925.224 106.308 1 0 |Stonetalon Mountains|',
            897.845, 925.224, 106.308, 1, 0.0, 'Stonetalon Mountains',
        ];
        yield 'no zone suffix' => [
            '10.5 20.5 30.5 0 1.25',
            10.5, 20.5, 30.5, 0, 1.25, null,
        ];
        yield 'trailing whitespace from socket read' => [
            "  1676.71 1678.31 121.67 0 2.70526 |Tirisfal Glades|  \n",
            1676.71, 1678.31, 121.67, 0, 2.70526, 'Tirisfal Glades',
        ];
    }

    #[DataProvider('realServerLines')]
    public function testItParsesRealServerOutput(
        string $line, float $x, float $y, float $z, int $map, float $o, ?string $zone,
    ): void {
        $position = Position::fromProtocolLine($line);

        self::assertSame($x, $position->x);
        self::assertSame($y, $position->y);
        self::assertSame($z, $position->z);
        self::assertSame($map, $position->mapId);
        self::assertSame($o, $position->orientation);
        self::assertSame($zone, $position->zone);
    }

    public static function malformedLines(): iterable
    {
        yield 'empty' => [''];
        yield 'too few fields' => ['1.0 2.0 3.0'];
        yield 'invalid guid response' => ['invalid guid'];
        yield 'non numeric' => ['a b c d e'];
        yield 'error text with zone-like pipes' => ['invalid request: nonsense'];
    }

    #[DataProvider('malformedLines')]
    public function testItRejectsMalformedLines(string $line): void
    {
        $this->expectException(WorldGatewayException::class);

        Position::fromProtocolLine($line);
    }

    public function testDistanceIgnoresHeight(): void
    {
        $a = new Position(0.0, 0.0, 0.0, 0);
        $b = new Position(3.0, 4.0, 999.0, 0);

        self::assertSame(5.0, $a->distanceTo($b));
    }

    public function testDistanceIsNullAcrossMaps(): void
    {
        $elwynn = new Position(0.0, 0.0, 0.0, 0);
        $outland = new Position(0.0, 0.0, 0.0, 530);

        self::assertNull($elwynn->distanceTo($outland));
    }

    public function testEqualsToleratesFloatJitter(): void
    {
        $a = new Position(-9464.75, 62.17, 56.0, 0);
        $b = new Position(-9464.7501, 62.1702, 56.0, 0, 3.97);

        self::assertTrue($a->equals($b), 'orientation and sub-centimetre drift must not count as a move');
    }
}
