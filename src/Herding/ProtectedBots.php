<?php

declare(strict_types=1);

namespace App\Herding;

use App\World\Model\Actor;

/**
 * Bots the herder must leave where they are, whatever the distance.
 *
 * An interface so the herder does not have to know *why* anyone is protected. It knew before:
 * HerdingWatcher reached into Director for the list of companions asked for by name, while
 * Director reached into Herding for the roster — a cycle between two layers, and the kind that
 * looks harmless until something needs moving.
 *
 * Now the dependency runs one way. Director implements this; Herding only knows that some bots
 * are spoken for.
 */
interface ProtectedBots
{
    /**
     * @return int[] guids that must survive a dismissal sweep
     */
    public function protectedFor(Actor $player): array;
}
