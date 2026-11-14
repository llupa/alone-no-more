<?php

declare(strict_types=1);

namespace App\World\Model;

/**
 * A character in the world — the player, or a bot. Deliberately one type for both: the whole
 * premise of this project is that a bot should be indistinguishable from a player at a glance,
 * and the code that stages a crowd or picks who answers you should not care which it is.
 */
final readonly class Actor
{
    public function __construct(
        public int $guid,
        public string $name,
        public CharacterRace $race,
        public CharacterClass $class,
        public Gender $gender,
        public int $level,
        public Position $position,
        public int $zoneId,
        public bool $isBot,
        public bool $isOnline,
        public ?string $guildName = null,
    ) {
    }

    public function faction(): Faction
    {
        return $this->race->faction();
    }

    /**
     * One line of identity, as a person would describe themselves. This is the seed of every
     * roleplay prompt, so it reads as prose rather than as a data dump.
     */
    public function describe(): string
    {
        return \sprintf(
            'a %s %s %s of the %s, level %d',
            $this->gender->label(),
            $this->race->label(),
            strtolower($this->class->label()),
            $this->faction()->value,
            $this->level,
        );
    }

    public function isSameFactionAs(self $other): bool
    {
        return $this->faction() === $other->faction();
    }

    /**
     * Whether this actor is close enough to the other to plausibly overhear them speak.
     * Null across maps, where distance has no meaning.
     */
    public function isWithin(float $yards, self $other): bool
    {
        $distance = $this->position->distanceTo($other->position);

        return null !== $distance && $distance <= $yards;
    }

    /**
     * Level-appropriateness, the single biggest factor in whether a bot belongs in a scene with
     * this player. A level 70 standing in Elwynn Forest reads as wrong however good the dialogue.
     */
    public function isPeerOf(self $other, int $tolerance = 5): bool
    {
        return abs($this->level - $other->level) <= $tolerance;
    }
}
