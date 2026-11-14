<?php

declare(strict_types=1);

namespace App\Replay;

use App\Knowledge\LevelProgressInterface;

/**
 * A recorded world has no experience bar.
 *
 * The snapshot captures where everyone stood and what they were carrying, not how far through a
 * level they were — it is a photograph, and experience is a thing that moves. Saying so is the
 * honest answer; inventing a number would make the replay demo subtly untrue.
 */
final readonly class ReplayLevelProgress implements LevelProgressInterface
{
    public function forCharacter(int $guid): ?array
    {
        return null;
    }
}
