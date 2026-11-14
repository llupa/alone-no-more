<?php

declare(strict_types=1);

namespace App\World\Chat;

use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use App\World\Model\Position;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the chat stream that mod-anm-bridge appends to `anm_chat_event`.
 *
 * The table is an append-only log and we consume it by watermark rather than by deleting rows:
 * the transcript is worth keeping for a bot's conversational memory, and a crash mid-reply must
 * not swallow the line that caused it.
 */
#[AsAlias(ChatEventRepositoryInterface::class)]
final readonly class ChatEventRepository implements ChatEventRepositoryInterface
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return ChatEvent[] oldest first, so a burst of chat is answered in the order it was said
     */
    public function since(int $watermark, int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.*, (a.username LIKE 'RNDBOT%') AS sender_is_bot
                FROM anm_chat_event e
                LEFT JOIN characters c ON c.guid = e.sender_guid
                LEFT JOIN acore_auth.account a ON a.id = c.account
                WHERE e.id > :watermark
                ORDER BY e.id
                LIMIT :limit
                SQL,
            ['watermark' => $watermark, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    /**
     * Highest id currently in the log. Used to start tailing from "now" rather than replaying
     * every line ever said when the app boots.
     */
    public function latestId(): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM anm_chat_event');
    }

    /**
     * What this bot and this player have already said to each other, oldest first, so the agent
     * can answer in context instead of greeting the player anew every time.
     *
     * @return ChatEvent[]
     */
    public function conversationBetween(int $botGuid, int $playerGuid, int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT e.*, (a.username LIKE 'RNDBOT%') AS sender_is_bot
                FROM anm_chat_event e
                LEFT JOIN characters c ON c.guid = e.sender_guid
                LEFT JOIN acore_auth.account a ON a.id = c.account
                WHERE e.sender_guid IN (:bot, :player)
                   OR e.receiver_guid IN (:bot, :player)
                ORDER BY e.id DESC
                LIMIT :limit
                SQL,
            ['bot' => $botGuid, 'player' => $playerGuid, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_reverse(array_values(array_filter(array_map($this->hydrate(...), $rows))));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?ChatEvent
    {
        // Channels we do not model (system, loot, combat log) are logged but not answerable.
        $channel = ChatChannel::tryFromRaw((int) $row['chat_type']);

        if (null === $channel) {
            return null;
        }

        return new ChatEvent(
            id: (int) $row['id'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            senderGuid: (int) $row['sender_guid'],
            senderName: $row['sender_name'],
            channel: $channel,
            message: $row['message'],
            receiverGuid: (int) $row['receiver_guid'],
            channelName: $row['channel'],
            position: new Position(
                (float) $row['position_x'],
                (float) $row['position_y'],
                (float) $row['position_z'],
                (int) $row['map'],
            ),
            senderIsBot: (bool) $row['sender_is_bot'],
        );
    }
}
