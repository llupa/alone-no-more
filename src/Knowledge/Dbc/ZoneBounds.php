<?php

declare(strict_types=1);

namespace App\Knowledge\Dbc;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Which zone a point in the world falls in, from WorldMapArea.dbc.
 *
 * The server stores a spawn as a map number and three floats. `characters.zone` exists for
 * players because the server computes it as they move, but nothing computes it for a creature or
 * a chest sitting in a table — those rows carry `zoneId = 0`. So the only way to answer "where
 * is this" from static data is to put the coordinates back inside a box.
 *
 * Without this the archivist could only name the continent, and told players things like "Grells
 * are in Kalimdor" — true, useless, and worse than silence because it sounds like an answer.
 *
 * WorldMapArea's 3.3.5a layout (11 fields, verified against the file's own header):
 *   0 ID   1 MapID   2 AreaID   3 AreaName   4 LocLeft   5 LocRight   6 LocTop   7 LocBottom
 *
 * The bounds are in WoW's own axes, where +x runs north and +y runs west — so "left" and "top"
 * are the *maxima* of y and x respectively, and a naive left<right reading has them inverted.
 */
final class ZoneBounds
{
    private const FIELD_MAP = 1;
    private const FIELD_AREA = 2;
    private const FIELD_NAME = 3;
    private const FIELD_LEFT = 4;
    private const FIELD_RIGHT = 5;
    private const FIELD_TOP = 6;
    private const FIELD_BOTTOM = 7;

    /** @var array<int, array{map: int, area: int, name: string, minX: float, maxX: float, minY: float, maxY: float}>|null */
    private ?array $boxes = null;

    public function __construct(
        #[Autowire('%env(ANM_DBC_DIR)%')]
        private readonly string $dbcDir,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * The area id of the zone containing a point, or null when nothing covers it — which happens
     * for instance interiors and a few unmapped corners, and is reported honestly rather than
     * guessed.
     *
     * An id rather than the name in this file: WorldMapArea carries the *map label*, which is
     * not always what a player calls the place — it has "Elwynn" where AreaTable, and everyone
     * playing, has "Elwynn Forest". Naming belongs to {@see AreaNames}.
     */
    public function areaAt(int $map, float $x, float $y): ?int
    {
        $best = null;
        $bestArea = \INF;

        foreach ($this->boxes() as $box) {
            if ($box['map'] !== $map) {
                continue;
            }

            if ($x < $box['minX'] || $x > $box['maxX'] || $y < $box['minY'] || $y > $box['maxY']) {
                continue;
            }

            // Boxes overlap — a zone's own box can sit inside a larger catch-all. The tightest
            // one that still contains the point is the most specific true answer.
            $area = ($box['maxX'] - $box['minX']) * ($box['maxY'] - $box['minY']);

            if ($area < $bestArea) {
                $best = $box['area'];
                $bestArea = $area;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array{map: int, area: int, name: string, minX: float, maxX: float, minY: float, maxY: float}>
     */
    private function boxes(): array
    {
        if (null !== $this->boxes) {
            return $this->boxes;
        }

        if (null === $this->cache) {
            return $this->boxes = $this->read();
        }

        /** @var array<int, array{map: int, area: int, name: string, minX: float, maxX: float, minY: float, maxY: float}> $boxes */
        $boxes = $this->cache->get('anm.dbc.worldmap.v1', fn (): array => $this->read());

        return $this->boxes = $boxes;
    }

    /**
     * @return array<int, array{map: int, area: int, name: string, minX: float, maxX: float, minY: float, maxY: float}>
     */
    private function read(): array
    {
        $path = rtrim($this->dbcDir, '/').'/WorldMapArea.dbc';

        // Without client data there are no boxes, so every point is "unknown" and callers fall
        // back to naming the continent. Degraded, not broken.
        if (!is_readable($path)) {
            return [];
        }

        $file = new DbcFile($path);
        $boxes = [];

        foreach ($file->records() as $fields) {
            $left = self::toFloat($fields[self::FIELD_LEFT]);
            $right = self::toFloat($fields[self::FIELD_RIGHT]);
            $top = self::toFloat($fields[self::FIELD_TOP]);
            $bottom = self::toFloat($fields[self::FIELD_BOTTOM]);

            // Battlegrounds and a few virtual rows carry a zero-size box; they can never contain
            // a point, so keeping them would only cost comparisons.
            if ($left === $right || $top === $bottom) {
                continue;
            }

            // Rows with no area id (the continent-wide maps) can never be named, so they would
            // only ever win the smallest-box contest and then resolve to nothing.
            if (0 === $fields[self::FIELD_AREA]) {
                continue;
            }

            $boxes[] = [
                'map' => $fields[self::FIELD_MAP],
                'area' => $fields[self::FIELD_AREA],
                'name' => $file->string($fields[self::FIELD_NAME]),
                'minX' => min($bottom, $top),
                'maxX' => max($bottom, $top),
                'minY' => min($right, $left),
                'maxY' => max($right, $left),
            ];
        }

        return $boxes;
    }

    /**
     * DBC records arrive as raw 32-bit words; these four fields are IEEE floats in disguise.
     */
    private static function toFloat(int $raw): float
    {
        /** @var array{1: float} $parsed */
        $parsed = unpack('f', pack('V', $raw));

        return $parsed[1];
    }
}
