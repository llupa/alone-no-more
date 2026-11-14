<?php

declare(strict_types=1);

namespace App\Roleplay\Selector;

use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Memory\SceneKey;
use App\Roleplay\Party\PartyVoice;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One companion speaks for the party, and the player chooses which.
 *
 * Replaces the round-robin policy. Spreading replies around the group stopped any one bot
 * dominating and destroyed the conversation doing it: each bot held only its own half, so a
 * follow-up landed on someone who had not heard the question. Companions answered as though
 * nobody else had spoken, apologised for contradicting themselves, and the party read as several
 * strangers talking past each other.
 *
 * The voice is whoever has been with you longest, and saying someone's name hands it to them —
 * which is how a person redirects a conversation anyway. Dismiss the voice and the next
 * longest-serving member picks it up with nothing to configure.
 *
 * {@see PartyBotSelector} keeps the rotation policy; it is no longer wired, but it is the right
 * shape for open-world chatter where there is no party and no obvious spokesman.
 */
#[AsAlias(BotSelectorInterface::class)]
final readonly class PartyVoiceSelector implements BotSelectorInterface
{
    public function __construct(
        private GroupRepositoryInterface $groups,
        private PartyVoice $voice,
        private ConversationStoreInterface $memory,
        /**
         * The only cooldown left. With one speaker there is no rotation to spread, so this is
         * pure anti-spam: it stops a companion answering twice while you are still typing.
         */
        #[Autowire(env: 'int:ANM_SOLE_BOT_COOLDOWN_SECONDS')]
        private int $cooldownSeconds = 3,
    ) {
    }

    public function select(ChatEvent $event, Actor $speaker, array $candidates): ?Actor
    {
        // A whisper names its recipient; party rules do not apply.
        if ($event->isDirected()) {
            foreach ($candidates as $candidate) {
                if ($candidate->guid === $event->receiverGuid && $candidate->isBot) {
                    return $candidate;
                }
            }

            return null;
        }

        if (!self::isPartyChannel($event->channel)) {
            return null;
        }

        $memberGuids = $this->groups->memberGuidsWith($speaker->guid);

        if ([] === $memberGuids) {
            return null;
        }

        $members = array_values(array_filter(
            $candidates,
            static fn (Actor $a): bool => $a->isBot && $a->isOnline && \in_array($a->guid, $memberGuids, true),
        ));

        if ([] === $members) {
            return null;
        }

        $partyKey = SceneKey::for($event, $speaker);

        if (null === $partyKey) {
            return null;
        }

        // Naming someone hands them the floor and keeps it there. Answering once and then
        // reverting would mean the next line goes back to a bot the player just turned away
        // from, which is the rotation problem wearing a different hat.
        $named = self::namedIn($event->message, $members);

        if (null !== $named) {
            // Saying a name hands over the floor for good, not for one line.
            $this->voice->handTo($partyKey, $named);
        }

        $chosen = $named ?? $this->voice->speakerFor($partyKey, $members);

        if (null === $chosen) {
            return null;
        }

        // Being named overrides the floor: ignoring someone right after saying their name is the
        // most obviously wrong thing this can do.
        if (null === $named && !$this->offCooldown($chosen)) {
            return null;
        }

        return $chosen;
    }

    private function offCooldown(Actor $bot): bool
    {
        $since = $this->memory->secondsSinceLastReply($bot->guid, $bot->name);

        return null === $since || $since >= $this->cooldownSeconds;
    }

    /**
     * The bot a message names, if any. Matched on a word boundary so "Lyliandra" does not answer
     * for "Lylia", and case-insensitively because nobody types character names exactly.
     *
     * @param Actor[] $members
     */
    private static function namedIn(string $message, array $members): ?Actor
    {
        foreach ($members as $member) {
            if (1 === preg_match('/\b'.preg_quote($member->name, '/').'\b/i', $message)) {
                return $member;
            }
        }

        return null;
    }

    private static function isPartyChannel(ChatChannel $channel): bool
    {
        return \in_array($channel, [
            ChatChannel::Party,
            ChatChannel::PartyLeader,
            ChatChannel::Raid,
            ChatChannel::RaidLeader,
        ], true);
    }
}
