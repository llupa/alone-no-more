<?php

declare(strict_types=1);

namespace App\Roleplay\Milestones;

/**
 * Something that happened to a player, worth a word from whoever is with them.
 */
final readonly class Milestone
{
    public function __construct(
        public int $id,
        public int $playerGuid,
        public string $playerName,
        /** 'level' or 'achievement'. */
        public string $kind,
        /** The achievement's name; empty for a level. */
        public string $detail,
        /** The new level, or the achievement id. */
        public int $number,
    ) {
    }

    /**
     * What happened, as a companion standing there would describe it.
     */
    public function describe(): string
    {
        return 'achievement' === $this->kind && '' !== $this->detail
            ? \sprintf('just earned the achievement "%s"', $this->detail)
            : \sprintf('just reached level %d', $this->number);
    }
}
