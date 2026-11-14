<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\World\Model\Position;

/**
 * Which way to walk, in words.
 *
 * Coordinates are useless to a player: nobody has them on screen, and "Milly Osworth is at
 * -8850, -224" is a worse answer than saying nothing. What people can act on is a direction and
 * a rough distance, the way you would tell someone in a tavern.
 *
 * WoW's axes are their own thing and the usual intuitions are wrong: +x runs **north** and +y
 * runs **west**. Reading them as screen coordinates sends people the opposite way, which is the
 * one failure worse than no directions at all.
 */
final readonly class Bearing
{
    public static function from(Position $me, Position $target): ?self
    {
        if ($me->mapId !== $target->mapId) {
            return null;
        }

        return new self(
            hypot($target->x - $me->x, $target->y - $me->y),
            self::compass($target->x - $me->x, $target->y - $me->y),
        );
    }

    private function __construct(
        public float $yards,
        public string $direction,
    ) {
    }

    /**
     * A sentence a person can follow.
     */
    public function describe(): string
    {
        return match (true) {
            $this->yards < 40 => 'right here, within a few paces',
            $this->yards < 150 => \sprintf('just %s of you, about %d paces', $this->direction, (int) round($this->yards)),
            $this->yards < 600 => \sprintf('a short walk %s, around %d paces', $this->direction, (int) round($this->yards)),
            $this->yards < 3000 => \sprintf('a fair way %s', $this->direction),
            default => \sprintf('a long journey %s', $this->direction),
        };
    }

    /**
     * Eight points of the compass. Anything finer is a precision we do not have — spawns are
     * averaged across a group — and a false precision reads as confidence we have not earned.
     */
    private static function compass(float $north, float $west): string
    {
        $northward = abs($north) > abs($west) * 2.414;
        $westward = abs($west) > abs($north) * 2.414;

        $ns = $north >= 0 ? 'north' : 'south';
        $ew = $west >= 0 ? 'west' : 'east';

        return match (true) {
            $northward => $ns,
            $westward => $ew,
            default => $ns.'-'.$ew,
        };
    }
}
