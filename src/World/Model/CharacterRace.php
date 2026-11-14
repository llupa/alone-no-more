<?php

declare(strict_types=1);

namespace App\World\Model;

/**
 * Playable races, with the values the server stores in `characters.race`.
 *
 * Verified against `enum Races` in src/server/shared/SharedDefines.h. Note the gaps: 9 (Goblin)
 * and 12+ exist in the enum but are not playable in 3.3.5a, so they are absent here — a
 * character row can never hold them.
 */
enum CharacterRace: int
{
    case Human = 1;
    case Orc = 2;
    case Dwarf = 3;
    case NightElf = 4;
    case Undead = 5;
    case Tauren = 6;
    case Gnome = 7;
    case Troll = 8;
    case BloodElf = 10;
    case Draenei = 11;

    public function faction(): Faction
    {
        return match ($this) {
            self::Human, self::Dwarf, self::NightElf, self::Gnome, self::Draenei => Faction::Alliance,
            self::Orc, self::Undead, self::Tauren, self::Troll, self::BloodElf => Faction::Horde,
        };
    }

    /**
     * How the race is named in game, which is what belongs in a roleplay prompt —
     * "Night Elf", not "NightElf".
     */
    public function label(): string
    {
        return match ($this) {
            self::NightElf => 'Night Elf',
            self::BloodElf => 'Blood Elf',
            self::Undead => 'Forsaken',
            default => $this->name,
        };
    }
}
