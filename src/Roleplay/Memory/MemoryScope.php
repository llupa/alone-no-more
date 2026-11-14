<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\World\Model\ChatChannel;

/**
 * How widely a line is heard, and therefore who should remember it.
 *
 * A whisper is between two people; party chat is heard by everyone in the group; /say is heard by
 * whoever is standing there. Keying all three the same way — one thread per bot and player — is
 * why two party members could each answer "are you coming?" as though the other had not just
 * answered it.
 */
enum MemoryScope
{
    /**
     * Which scope a channel belongs to.
     *
     * Lives here rather than on ChatChannel: a chat channel is a fact about the game, and it has
     * no business knowing what a memory is. The mapping is ours, so it belongs on our side of
     * the line.
     */
    public static function of(ChatChannel $channel): self
    {
        return match ($channel) {
            ChatChannel::Whisper => self::Private,
            ChatChannel::Say, ChatChannel::Yell, ChatChannel::Channel,
            ChatChannel::Emote, ChatChannel::TextEmote => self::Vicinity,
            default => self::Group,
        };
    }

    /** Between one bot and one player. The MessageBag already holds it as real turns. */
    case Private;

    /** Heard by the whole group, wherever its members are standing. */
    case Group;

    /** Heard by whoever is close enough. */
    case Vicinity;
}
