<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * How far a character is through their current level.
 *
 * Asked "how much more experience do you need", a companion said to look at the experience bar.
 * Fair enough for the player's own, and no answer at all about its own — it simply had no way
 * to know. The server does: `characters.xp` against `player_xp_for_level`.
 */
interface LevelProgressInterface
{
    /**
     * @return array{level: int, xp: int, needed: int, remaining: int, percent: int}|null
     *                                                                                    null when the character is unknown, or at
     *                                                                                    the level cap where there is nothing left
     *                                                                                    to earn
     */
    public function forCharacter(int $guid): ?array;
}
