<?php

declare(strict_types=1);

namespace App\Roleplay\Selector;

use App\Director\Settings;
use App\Roleplay\Memory\ConversationStoreInterface;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Your party answers you.
 *
 * Chosen over proximity for the first real version because membership is explicit: there is no
 * question of who could have heard, the candidate set is at most four, and a party is where
 * people actually talk to each other in this game.
 *
 * Two rules keep it from feeling mechanical:
 *   - whoever has been quiet longest speaks, so one bot cannot monopolise the conversation;
 *   - a bot that has just spoken sits the next one out.
 *
 * No longer wired: {@see PartyVoiceSelector} replaced it, because spreading replies around a
 * party destroyed the conversation it was sharing out. Kept because the policy is right for
 * open-world chatter, where there is no party and no obvious spokesman.
 */
final readonly class PartyBotSelector implements BotSelectorInterface
{
    public function __construct(
        private GroupRepositoryInterface $groups,
        private ConversationStoreInterface $memory,
        /**
         * How long a bot sits out after speaking. Null reads the live setting, so "they talk too
         * much" is a sentence to an assistant rather than an edit and a restart; tests pass an
         * explicit number because they are about the rule, not about where it came from.
         */
        private ?int $cooldownSeconds = null,
        /**
         * Floor applied when a bot is the only one who could answer. The long cooldown exists to
         * share the conversation around a party; with a single bot there is nobody to share it
         * with, so enforcing it just makes your one companion ignore you. This keeps it from
         * answering faster than a person could type while still always answering.
         */
        #[Autowire(env: 'int:ANM_SOLE_BOT_COOLDOWN_SECONDS')]
        private int $soleBotCooldownSeconds = 3,
        /**
         * How long a conversation stays with whoever is in it. Reply inside this window and the
         * same bot answers, whatever the rotation says — otherwise a follow-up question gets
         * handed to someone with no idea what you are referring to.
         */
        #[Autowire(env: 'int:ANM_STICKY_SECONDS')]
        private int $stickySeconds = 90,
        private ?Settings $settings = null,
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

        $members = array_filter(
            $candidates,
            static fn (Actor $a): bool => $a->isBot && $a->isOnline && \in_array($a->guid, $memberGuids, true),
        );

        if ([] === $members) {
            return null;
        }

        $cooldown = \count($members) > 1 ? $this->replyCooldown() : $this->soleBotCooldownSeconds;

        // Addressed by name? That bot answers, cooldown and rotation aside. Being ignored after
        // saying someone's name is the single most obviously wrong thing this can do.
        if (null !== $named = self::namedIn($event->message, $members)) {
            return $named;
        }

        // Whoever is mid-conversation answers under the short anti-spam floor rather than the
        // full rotation cooldown. The two rules want different things: the cooldown spreads
        // idle chatter around the party, while a focused exchange has to stay coherent. The
        // cooldown is longer than the gap between conversational turns, so without this
        // exemption replying quickly hands your follow-up to a bot that was not in the
        // conversation — incoherence is worse than one bot talking twice.
        $holder = $this->stillHoldingTheThread($members);

        $ready = array_filter($members, fn (Actor $bot): bool => $this->offCooldown(
            $bot,
            $bot === $holder ? $this->soleBotCooldownSeconds : $cooldown,
        ));

        // With several bots, everyone cooling down means the party stays quiet for a beat, which
        // is what a real group does. With one bot it would mean being ignored, which is why the
        // floor above is short.
        if ([] === $ready) {
            return null;
        }

        // Stickiness decides who answers among those eligible, never whether the party answers
        // at all — if even the thread-holder is inside the anti-spam floor, the party stays quiet.
        if (null !== $holder && \in_array($holder, $ready, true)) {
            return $holder;
        }

        usort($ready, fn (Actor $a, Actor $b): int => $this->quietFor($b) <=> $this->quietFor($a));

        return $ready[0];
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
            if (preg_match('/(?<![\\w])'.preg_quote($member->name, '/').'(?![\\w])/iu', $message)) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Whoever spoke most recently, if they spoke recently enough that the exchange is still
     * live. Rotation is for lulls, not for the middle of a conversation.
     *
     * @param Actor[] $members
     */
    private function stillHoldingTheThread(array $members): ?Actor
    {
        $holder = null;
        $quietest = \PHP_INT_MAX;

        foreach ($members as $member) {
            $since = $this->memory->secondsSinceLastReply($member->guid, $member->name);

            if (null !== $since && $since < $quietest) {
                $quietest = $since;
                $holder = $member;
            }
        }

        return $quietest <= $this->stickySeconds ? $holder : null;
    }

    private function replyCooldown(): int
    {
        return $this->cooldownSeconds ?? $this->settings?->replyCooldownSeconds() ?? 20;
    }

    private function offCooldown(Actor $bot, int $cooldownSeconds): bool
    {
        $since = $this->memory->secondsSinceLastReply($bot->guid, $bot->name);

        return null === $since || $since >= $cooldownSeconds;
    }

    /**
     * Seconds of silence, with never-spoken treated as the longest wait so a new voice is
     * preferred over one that has already been heard.
     */
    private function quietFor(Actor $bot): int
    {
        return $this->memory->secondsSinceLastReply($bot->guid, $bot->name) ?? \PHP_INT_MAX;
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
