<?php

declare(strict_types=1);

namespace App\Roleplay\Selector;

use App\World\Model\Actor;
use App\World\Model\ChatEvent;

/**
 * Decides which bot, if any, answers a line of chat.
 *
 * A policy rather than a function because "nearest" is only the first answer. Later candidates:
 * whoever the player has spoken to most (affinity), round-robin so one bot does not dominate,
 * or class/race suitability for what was asked. Swapping the policy must not touch the responder.
 */
interface BotSelectorInterface
{
    /**
     * @param Actor[] $candidates bots eligible to answer, in no particular order
     *
     * @return Actor|null null when nobody should answer, which is a legitimate and common outcome:
     *                    silence is better than a bot shouting across a zone it cannot hear
     */
    public function select(ChatEvent $event, Actor $speaker, array $candidates): ?Actor;
}
