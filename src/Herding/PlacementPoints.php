<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Model\Position;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Places in the world where a character can plausibly be standing.
 *
 * Taken from creature spawn points rather than invented coordinates: a spawn is somewhere the
 * designers decided a creature can stand, which means it is on the ground, inside the playable
 * area and reachable. Random coordinates in a radius land inside cliffs and under lakes.
 *
 * Chosen by distance from the player rather than by zone id, because `creature.zoneId` is
 * populated at runtime and is 0 for almost every row on disk — and proximity is what we actually
 * want anyway.
 */
final readonly class PlacementPoints
{
    /**
     * Nobody should materialise in your field of view. 80 yards is beyond the distance a player
     * tracks individual figures, so a bot that logs in there reads as someone who was already
     * about their business.
     */
    private const MIN_YARDS = 80.0;

    public function __construct(
        #[Autowire(service: 'anm.world_connection')]
        private Connection $world,
    ) {
    }

    /**
     * Spots around a point, spread out rather than clustered.
     *
     * Grouped onto a coarse grid so a hundred spawns of the same wolf pack yield one place, not
     * a hundred — otherwise every bot lands on top of the same camp.
     *
     * @return Position[] in no particular order
     */
    public function around(Position $centre, float $maxYards = 600.0, int $limit = 60): array
    {
        $rows = $this->world->fetchAllAssociative(
            <<<'SQL'
                SELECT AVG(c.position_x) AS x, AVG(c.position_y) AS y, AVG(c.position_z) AS z
                FROM creature c
                JOIN creature_template ct ON ct.entry = c.id
                WHERE c.map = :map
                  AND (ct.flags_extra & 0x80) = 0
                  AND ct.name NOT LIKE '[%'
                  AND POW(c.position_x - :x, 2) + POW(c.position_y - :y, 2) BETWEEN :min2 AND :max2
                GROUP BY ROUND(c.position_x / 50), ROUND(c.position_y / 50)
                ORDER BY RAND()
                LIMIT :limit
                SQL,
            [
                'map' => $centre->mapId,
                'x' => $centre->x,
                'y' => $centre->y,
                'min2' => self::MIN_YARDS * self::MIN_YARDS,
                'max2' => $maxYards * $maxYards,
                'limit' => $limit,
            ],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $r): Position => new Position(
                (float) $r['x'],
                (float) $r['y'],
                (float) $r['z'],
                $centre->mapId,
            ),
            $rows,
        );
    }
}
