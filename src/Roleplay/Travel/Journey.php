<?php

declare(strict_types=1);

namespace App\Roleplay\Travel;

use App\World\Model\Position;

/**
 * A companion walking somewhere, and what to do when it gets there.
 *
 * `go` is a single movement order, so a bot that is also following its master drifts back the
 * moment the walk ends. Telling it to `stay` on arrival is what turns "walk over there" into
 * "wait for me there" — and the mod handles `follow` perfectly well afterwards, so getting it
 * moving again is the player's word away.
 *
 * Hot memory, like an errand: a walk is the next minute, not a fact about the world.
 */
final class Journey
{
    public function __construct(
        public readonly int $botGuid,
        public readonly Position $destination,
        public readonly string $towards,
        public readonly float $startedAt,
        /** Close enough. The bot stops short of the target and pathing rarely lands on a point. */
        public readonly float $arrivalYards = 10.0,
        /** A walk that never finishes must not leave a bot marked as travelling for ever. */
        public readonly float $giveUpAfter = 180.0,
    ) {
        $this->lastNudgeAt = $startedAt;
    }

    /**
     * When the destination was last re-sent.
     *
     * `go` is one movement order and the follow strategy outlives it: told to lead the way, a
     * companion walked a few paces, got pulled back toward its master, and stopped. The player
     * worked around it by typing "lead me there" five times and watching the gap close 283 →
     * 200 → 190 → 140 → 113. Re-sending on a timer is that workaround, done properly.
     */
    public float $lastNudgeAt = 0.0;

    public function needsNudge(float $now, float $everySeconds): bool
    {
        return $now - $this->lastNudgeAt >= $everySeconds;
    }

    public function hasArrived(Position $at): bool
    {
        return $at->mapId === $this->destination->mapId
            && hypot($at->x - $this->destination->x, $at->y - $this->destination->y) <= $this->arrivalYards;
    }

    public function gaveUp(float $now): bool
    {
        return $now - $this->startedAt > $this->giveUpAfter;
    }
}
