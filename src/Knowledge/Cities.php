<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * The places a companion cannot be walked through.
 *
 * Leading the way works by handing a destination to the server's own navigation, and in open
 * country that is reliable — a companion crosses Elwynn to a quest giver without trouble. A
 * city is a different problem: doors, bridges, lifts, several floors stacked on one footprint,
 * and a navmesh that has to be followed exactly rather than approximately. Asked from inside
 * Stormwind for a guard out in Elwynn, a companion simply stood still, because every route out
 * ran through a wall.
 *
 * Refusing is better than that. A player standing in a capital has signposts, a map and a flight
 * master; what they do not have is a companion who promises a journey and then does not move.
 *
 * Capitals and sanctuaries only. Villages like Goldshire are open ground with buildings on it
 * and lead perfectly well.
 */
final class Cities
{
    /**
     * Zone ids from AreaTable, which for a capital is also the id {@see Dbc\ZoneBounds} returns
     * for any point inside it.
     */
    private const ZONES = [
        1519 => 'Stormwind City',
        1537 => 'Ironforge',
        1657 => 'Darnassus',
        1637 => 'Orgrimmar',
        1638 => 'Thunder Bluff',
        1497 => 'Undercity',
        3487 => 'Silvermoon City',
        3557 => 'The Exodar',
        3703 => 'Shattrath City',
        4395 => 'Dalaran',
    ];

    public static function isCity(?int $zoneId): bool
    {
        return null !== $zoneId && isset(self::ZONES[$zoneId]);
    }

    public static function name(?int $zoneId): ?string
    {
        return null !== $zoneId ? (self::ZONES[$zoneId] ?? null) : null;
    }
}
