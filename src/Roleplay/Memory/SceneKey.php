<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\World\Model\Actor;
use App\World\Model\ChatEvent;

/**
 * Which conversation a line belongs to.
 *
 * Group chat is keyed on the player rather than on the group's own id: we log bots in and out of
 * the party constantly to herd them, so the group identity churns while the player's experience
 * of "what was just said around me" does not. Keying on the group would wipe the scene every
 * time we swapped a companion.
 */
final class SceneKey
{
    /**
     * Null when the line belongs to no shared scene — a whisper is between two people, and the
     * message bag already holds it.
     */
    public static function for(ChatEvent $event, Actor $speaker): ?string
    {
        return match (MemoryScope::of($event->channel)) {
            MemoryScope::Private => null,
            MemoryScope::Group => 'party:'.$speaker->guid,
            MemoryScope::Vicinity => 'near:'.$speaker->zoneId,
        };
    }
}
