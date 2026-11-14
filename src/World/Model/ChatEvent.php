<?php

declare(strict_types=1);

namespace App\World\Model;

/**
 * One line of chat, as captured by mod-anm-bridge from the core's OnPlayerCanUseChat hooks.
 *
 * `chatType` is the raw WoW CHAT_MSG_* opcode rather than an enum of our own, because the
 * server speaks that vocabulary and translating twice would only lose information.
 */
final readonly class ChatEvent
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $createdAt,
        public int $senderGuid,
        public string $senderName,
        public ChatChannel $channel,
        public string $message,
        public int $receiverGuid,
        public string $channelName,
        public Position $position,
        public bool $senderIsBot,
    ) {
    }

    public function isFromPlayer(): bool
    {
        return !$this->senderIsBot;
    }

    /**
     * Whether this line was aimed at one specific character, which decides whether a reply is
     * expected of a named bot or merely possible from whoever is nearby.
     */
    public function isDirected(): bool
    {
        return 0 !== $this->receiverGuid;
    }
}
