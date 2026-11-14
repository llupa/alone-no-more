<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

use App\World\Model\CharacterClass;
use App\World\Model\Faction;

/**
 * What a calling makes someone *do*, and what a war makes them feel about the other side.
 *
 * A trade is not a temperament. "You are a paladin" tells a model nothing it will act on, while
 * "judge people when they stray from the Light" is a thing to do in a sentence — the difference
 * between a character sheet and a character.
 *
 * The faction line is about an army, not a people. Hostility toward the Horde is what the setting
 * is built on and makes a companion feel like it lives there; contempt for someone's race is a
 * different thing entirely, and nothing here says it.
 */
final readonly class Creed
{
    public static function ofClass(CharacterClass $class): string
    {
        return match ($class) {
            CharacterClass::Paladin => 'Judge people, gently but out loud, when they stray from the Light.',
            CharacterClass::Priest => 'Watch for who is hurt or frightened and tend to them before anything else.',
            CharacterClass::Warrior => 'Weigh every situation as a fight: who stands where, and what you would do first.',
            CharacterClass::Rogue => 'Notice what people are not saying, and keep your own counsel.',
            CharacterClass::Hunter => 'Speak of your beast as a companion, and read the ground and the weather aloud.',
            CharacterClass::Mage => 'Correct people when they are wrong about magic. They usually are.',
            CharacterClass::Warlock => 'Treat your power as a bargain you have made and do not care to discuss.',
            CharacterClass::Shaman => 'Ask what the elements think of a thing before you say what you think.',
            CharacterClass::Druid => 'Measure everything against the balance of the wild, and say when it is out.',
            CharacterClass::DeathKnight => 'Speak of your own death plainly, and of the living as though you are not quite one of them.',
            default => 'Do the work in front of you and do it properly.',
        };
    }

    public static function ofFaction(Faction $faction): string
    {
        return match ($faction) {
            Faction::Alliance => 'Speak ill of the Horde whenever they come up.',
            Faction::Horde => 'Speak ill of the Alliance whenever they come up.',
            default => '',
        };
    }
}
