<?php

declare(strict_types=1);

namespace App\Tests\Roleplay;

use App\World\Model\Actor;
use App\World\Model\CharacterClass;
use App\World\Model\CharacterRace;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use App\World\Model\Gender;
use App\World\Model\Position;

/**
 * Builders for the handful of objects every roleplay test needs, so each test states only what
 * it actually cares about.
 */
final class ActorMother
{
    public static function bot(
        int $guid,
        string $name = 'Bot',
        int $level = 10,
        ?Position $at = null,
        CharacterRace $race = CharacterRace::NightElf,
        CharacterClass $class = CharacterClass::Warrior,
    ): Actor {
        return new Actor(
            guid: $guid,
            name: $name,
            race: $race,
            class: $class,
            gender: Gender::Male,
            level: $level,
            position: $at ?? new Position(0, 0, 0, 1),
            zoneId: 141,
            isBot: true,
            isOnline: true,
        );
    }

    public static function player(
        int $guid = 100,
        string $name = 'Doppel',
        int $level = 10,
        ?Position $at = null,
    ): Actor {
        return new Actor(
            guid: $guid,
            name: $name,
            race: CharacterRace::NightElf,
            class: CharacterClass::Rogue,
            gender: Gender::Male,
            level: $level,
            position: $at ?? new Position(0, 0, 0, 1),
            zoneId: 141,
            isBot: false,
            isOnline: true,
        );
    }

    public static function said(
        string $message,
        ChatChannel $channel = ChatChannel::PartyLeader,
        int $senderGuid = 100,
        string $senderName = 'Doppel',
        int $receiverGuid = 0,
    ): ChatEvent {
        return new ChatEvent(
            id: 1,
            createdAt: new \DateTimeImmutable('2026-11-12 18:00:00'),
            senderGuid: $senderGuid,
            senderName: $senderName,
            channel: $channel,
            message: $message,
            receiverGuid: $receiverGuid,
            channelName: '',
            position: new Position(0, 0, 0, 1),
            senderIsBot: false,
        );
    }
}
