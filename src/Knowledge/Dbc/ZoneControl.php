<?php

declare(strict_types=1);

namespace App\Knowledge\Dbc;

use App\World\Model\Faction;

/**
 * Who holds a zone, from AreaTable.dbc's FactionGroupMask.
 *
 * Verified against zones whose answer is not in doubt: Elwynn, Teldrassil, Dun Morogh and
 * Stormwind all report 2; Durotar, the Barrens, Tirisfal and Orgrimmar all report 4;
 * Stranglethorn, Ashenvale and Desolace all report 0.
 *
 * This is what lets a crowd belong where it stands. Without it the faction mix is whatever the
 * shuffle produced — in testing, 22 Alliance and 28 Horde in Teldrassil, which is the Night Elf
 * home zone and should be almost entirely Alliance. A player reads that instantly as wrong, long
 * before they notice anything about dialogue.
 */
enum ZoneControl: int
{
    case Contested = 0;
    case Alliance = 2;
    case Horde = 4;

    public function heldBy(): ?Faction
    {
        return match ($this) {
            self::Alliance => Faction::Alliance,
            self::Horde => Faction::Horde,
            self::Contested => null,
        };
    }

    /**
     * What share of a crowd here should belong to the holding faction.
     *
     * Not all of it: a hostile scout or two in an enemy zone is a normal sight in this game, and
     * a zone with literally nobody from the other side feels sterile. Contested ground splits
     * evenly, which is the whole character of a place like Ashenvale.
     */
    public function majorityShare(): float
    {
        return self::Contested === $this ? 0.5 : 0.85;
    }

    public function describe(): string
    {
        return match ($this) {
            self::Alliance => 'held by the Alliance',
            self::Horde => 'held by the Horde',
            self::Contested => 'contested ground',
        };
    }
}
