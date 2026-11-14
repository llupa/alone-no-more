<?php

declare(strict_types=1);

namespace App\Roleplay\Selector;

use App\World\Model\Actor;
use App\World\Model\ChatEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The closest bot that could plausibly have heard, answers.
 *
 * Not the active policy — PartyBotSelector is. Kept because proximity is the right answer
 * for open-world chatter once party chat is proven, and swapping back is a one-line change.
 *
 * Proximity rules follow the game's own: /say carries about 25 yards, /yell about 300, and
 * channel, guild, party and whisper do not depend on distance at all. Answering a /say from
 * across the zone is the fastest way to break the illusion, so the radius is enforced here
 * rather than left to the model.
 */
final readonly class NearestBotSelector implements BotSelectorInterface
{
    /** Yards a normal /say carries in WoW. */
    private const SAY_RANGE = 25.0;

    /** Yards a /yell carries. */
    private const YELL_RANGE = 300.0;

    public function __construct(
        #[Autowire(env: 'int:ANM_PEER_LEVEL_TOLERANCE')]
        private int $levelTolerance = 5,
    ) {
    }

    public function select(ChatEvent $event, Actor $speaker, array $candidates): ?Actor
    {
        // A whisper names its recipient; nobody else gets to answer it.
        if ($event->isDirected()) {
            foreach ($candidates as $candidate) {
                if ($candidate->guid === $event->receiverGuid) {
                    return $candidate;
                }
            }

            return null;
        }

        $eligible = array_filter(
            $candidates,
            fn (Actor $bot): bool => $this->canAnswer($bot, $speaker, $event),
        );

        if ([] === $eligible) {
            return null;
        }

        usort(
            $eligible,
            static fn (Actor $a, Actor $b): int => ($a->position->distanceTo($speaker->position) ?? \PHP_FLOAT_MAX)
                <=> ($b->position->distanceTo($speaker->position) ?? \PHP_FLOAT_MAX),
        );

        return $eligible[0];
    }

    private function canAnswer(Actor $bot, Actor $speaker, ChatEvent $event): bool
    {
        if ($bot->guid === $speaker->guid || !$bot->isBot || !$bot->isOnline) {
            return false;
        }

        // A level 70 answering a level 5 reads as wrong however good the dialogue.
        if (!$bot->isPeerOf($speaker, $this->levelTolerance)) {
            return false;
        }

        if (!$event->channel->isProximityBased()) {
            // Guild and party chat carry regardless of distance, but only to actual members —
            // membership is established by the caller when it assembles the candidate list.
            return true;
        }

        $distance = $bot->position->distanceTo($speaker->position);

        if (null === $distance) {
            return false;
        }

        return $distance <= $this->rangeFor($event);
    }

    private function rangeFor(ChatEvent $event): float
    {
        return match ($event->channel) {
            \App\World\Model\ChatChannel::Yell => self::YELL_RANGE,
            // A numbered channel is zone-wide in game; we still prefer someone close enough to
            // be a plausible presence rather than a disembodied voice.
            \App\World\Model\ChatChannel::Channel => self::YELL_RANGE,
            default => self::SAY_RANGE,
        };
    }
}
