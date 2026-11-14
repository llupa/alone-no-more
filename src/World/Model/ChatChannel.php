<?php

declare(strict_types=1);

namespace App\World\Model;

/**
 * The subset of WoW's CHAT_MSG_* opcodes we act on, with the raw values the client and server use.
 *
 * Values verified against `enum ChatMsg` in the core's src/server/shared/SharedDefines.h rather
 * than transcribed from memory. Anything not listed (system, combat log, loot, monster chat,
 * channel join/leave notices) is still captured by the bridge but never answered.
 *
 * Note the leader variants: a party or raid message sent by the group leader arrives with a
 * different opcode than the same message from a member, so both must map to the same channel or
 * every line from a leader would be silently ignored.
 */
enum ChatChannel: int
{
    case Say = 0x01;
    case Party = 0x02;
    case Raid = 0x03;
    case Guild = 0x04;
    case Officer = 0x05;
    case Yell = 0x06;
    case Whisper = 0x07;
    case Emote = 0x0A;
    case TextEmote = 0x0B;
    case Channel = 0x11;
    case RaidLeader = 0x27;
    case RaidWarning = 0x28;
    case Battleground = 0x2C;
    case BattlegroundLeader = 0x2D;
    case PartyLeader = 0x33;

    public static function tryFromRaw(int $raw): ?self
    {
        return self::tryFrom($raw);
    }

    /**
     * How a bot should answer a line heard on this channel. Replying to /say with a whisper
     * would feel wrong, and replying to a whisper with /say would broadcast a private exchange.
     *
     * Maps to the `action` column of anm_outbox, which mod-anm-bridge dispatches.
     */
    public function replyAction(): string
    {
        return match ($this) {
            self::Say, self::Yell, self::Channel, self::Emote, self::TextEmote => 'say',
            self::Party, self::PartyLeader, self::Raid, self::RaidLeader,
            self::RaidWarning, self::Battleground, self::BattlegroundLeader => 'party',
            self::Guild, self::Officer => 'guild',
            self::Whisper => 'whisper',
        };
    }

    /**
     * Whether hearing this channel implies the bot is physically near the speaker.
     * Guild and party chat carry across the world; /say does not.
     */
    public function isProximityBased(): bool
    {
        return match ($this) {
            self::Say, self::Yell, self::Channel, self::Emote, self::TextEmote => true,
            default => false,
        };
    }

    /**
     * Lines a bot may answer. Raid warnings and battleground chatter are captured for context
     * but answering them would be noise.
     */
    public function invitesReply(): bool
    {
        return match ($this) {
            self::RaidWarning, self::Battleground, self::BattlegroundLeader => false,
            default => true,
        };
    }
}
