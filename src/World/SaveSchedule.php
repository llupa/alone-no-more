<?php

declare(strict_types=1);

namespace App\World;

/**
 * When the listener should ask the server to save everyone.
 *
 * Positions are read from the characters table, which the server only writes on a save, so a
 * `saveall` comes before reading. It used to come every pass, once a second: a full save of
 * every character online, a herded crowd of up to four hundred included, queued faster than the
 * database could take it.
 *
 * Every few seconds is enough for herding, arrivals and errands. Answering chat is the one place
 * that cannot wait, because who is in earshot of a /say is a question about where people stand
 * right now — so a line waiting to be answered always gets a fresh save.
 */
final class SaveSchedule
{
    private ?float $lastSave = null;

    public function __construct(private readonly float $everySeconds = 5.0)
    {
    }

    /**
     * Whether to save on this pass. Saying yes counts as saving.
     */
    public function isDue(float $now, bool $chatWaiting): bool
    {
        if (!$chatWaiting && null !== $this->lastSave && $now - $this->lastSave < $this->everySeconds) {
            return false;
        }

        $this->lastSave = $now;

        return true;
    }
}
