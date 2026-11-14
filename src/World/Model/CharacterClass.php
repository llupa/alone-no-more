<?php

declare(strict_types=1);

namespace App\World\Model;

/**
 * Playable classes, with the values the server stores in `characters.class`.
 * Verified against `enum Classes` in src/server/shared/SharedDefines.h (10 is unused).
 */
enum CharacterClass: int
{
    case Warrior = 1;
    case Paladin = 2;
    case Hunter = 3;
    case Rogue = 4;
    case Priest = 5;
    case DeathKnight = 6;
    case Shaman = 7;
    case Mage = 8;
    case Warlock = 9;
    case Druid = 11;

    public function label(): string
    {
        return self::DeathKnight === $this ? 'Death Knight' : $this->name;
    }

    /**
     * The role a bot would fill in a group. Used both for staging a believable crowd and for
     * colouring how a bot talks — a tank and a healer do not describe the same fight the same way.
     */
    public function role(): string
    {
        return match ($this) {
            self::Warrior, self::Paladin, self::DeathKnight, self::Druid => 'hybrid',
            self::Priest, self::Shaman => 'healer',
            self::Hunter, self::Rogue, self::Mage, self::Warlock => 'damage',
        };
    }
}
