<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\World\Model\ChatEvent;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What each bot remembers of each player, in our own SQLite file.
 *
 * Deliberately separate from AzerothCore's databases: this is our state, the server knows nothing
 * about it, and a player wiping their character should not wipe what a bot remembers of them.
 *
 * The transcript lives in `anm_chat_event` already, but that is a firehose of everything said by
 * everyone. This is the distilled version — only exchanges a given bot took part in — because the
 * prompt budget is a handful of lines, not a day's chat log.
 */
#[AsAlias(ConversationStoreInterface::class)]
final class ConversationStore implements ConversationStoreInterface
{
    private ?Connection $connection = null;

    public function __construct(
        #[Autowire('%anm.state_file%')]
        private readonly string $path,
    ) {
    }

    public function remember(int $botGuid, int $playerGuid, string $speaker, string $message): void
    {
        $this->connection()->insert('conversation', [
            'bot_guid' => $botGuid,
            'player_guid' => $playerGuid,
            'speaker' => $speaker,
            'message' => $message,
            'said_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function rememberExchange(ChatEvent $event, int $botGuid, string $reply, string $botName): void
    {
        $this->remember($botGuid, $event->senderGuid, $event->senderName, $event->message);
        $this->remember($botGuid, $event->senderGuid, $botName, $reply);
        $this->touchAffinity($botGuid, $event->senderGuid);
    }

    /**
     * The last few exchanges, oldest first — enough for a callback ("How was Elwynn Forest?")
     * without spending the whole prompt budget on history.
     *
     * @return array<int, array{speaker: string, message: string, said_at: string}>
     */
    public function recent(int $botGuid, int $playerGuid, int $limit = 20): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT speaker, message, said_at FROM conversation
             WHERE bot_guid = :bot AND player_guid = :player
             ORDER BY id DESC LIMIT :limit',
            ['bot' => $botGuid, 'player' => $playerGuid, 'limit' => $limit],
        );

        return array_map(self::asLine(...), array_reverse($rows));
    }

    /**
     * Record a line as heard by everyone in a scene — a party, or a patch of ground.
     *
     * Separate from `conversation`, which is deliberately pairwise: this is the room, that is
     * the relationship. A line belongs in both, for different readers.
     */
    public function rememberHeard(string $sceneKey, int $speakerGuid, string $speaker, string $message): void
    {
        $this->connection()->insert('scene', [
            'scene_key' => $sceneKey,
            'speaker_guid' => $speakerGuid,
            'speaker' => $speaker,
            'message' => $message,
            'said_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * What was said in a scene, oldest first, leaving out one speaker's own lines.
     *
     * The exclusions are the point. A bot's own words reach it as assistant turns in the message
     * bag, where a model reads them as its own; handing them back as "something you overheard"
     * would have it reacting to itself. The player's words arrive as user turns for the same
     * reason. What is left is exactly what this bot would otherwise have missed — what its
     * companions said while someone else was answering.
     *
     * @param int[] $excludingGuids
     *
     * @return array<int, array{speaker: string, message: string, said_at: string}>
     */
    public function sceneLines(string $sceneKey, array $excludingGuids, int $limit = 12): array
    {
        if ([] === $excludingGuids) {
            $excludingGuids = [0];
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT speaker, message, said_at FROM scene
             WHERE scene_key = :scene AND speaker_guid NOT IN (:excluded)
             ORDER BY id DESC LIMIT :limit',
            ['scene' => $sceneKey, 'excluded' => $excludingGuids, 'limit' => $limit],
            ['excluded' => ArrayParameterType::INTEGER],
        );

        return array_map(self::asLine(...), array_reverse($rows));
    }

    /**
     * How well a bot knows a player: how many exchanges, and when they last spoke.
     *
     * @return array{exchanges: int, last_seen: string}|null
     */
    public function acquaintance(int $botGuid, int $playerGuid): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT exchanges, last_seen FROM affinity WHERE bot_guid = :bot AND player_guid = :player',
            ['bot' => $botGuid, 'player' => $playerGuid],
        );

        return false === $row
            ? null
            : ['exchanges' => (int) $row['exchanges'], 'last_seen' => (string) $row['last_seen']];
    }

    /**
     * When this bot last said anything to anyone. Backs the cooldown that stops one bot
     * answering every single line — the fastest way to make a party feel like one voice
     * wearing several names.
     */
    public function lastSpokeAt(int $botGuid): ?\DateTimeImmutable
    {
        $at = $this->connection()->fetchOne(
            'SELECT said_at FROM conversation WHERE bot_guid = :bot AND speaker <> :player_marker
             ORDER BY id DESC LIMIT 1',
            ['bot' => $botGuid, 'player_marker' => ''],
        );

        return false === $at || null === $at ? null : new \DateTimeImmutable((string) $at);
    }

    /**
     * Seconds since this bot last spoke, or null if it never has.
     */
    public function secondsSinceLastReply(int $botGuid, string $botName): ?int
    {
        $at = $this->connection()->fetchOne(
            'SELECT said_at FROM conversation WHERE bot_guid = :bot AND speaker = :name
             ORDER BY id DESC LIMIT 1',
            ['bot' => $botGuid, 'name' => $botName],
        );

        if (false === $at || null === $at) {
            return null;
        }

        return time() - (new \DateTimeImmutable((string) $at))->getTimestamp();
    }

    public function hasSpokenBefore(int $botGuid, int $playerGuid): bool
    {
        return (bool) $this->connection()->fetchOne(
            'SELECT 1 FROM conversation WHERE bot_guid = :bot AND player_guid = :player LIMIT 1',
            ['bot' => $botGuid, 'player' => $playerGuid],
        );
    }

    /**
     * Bots this player has talked to most, most familiar first. The seed of "I know this name" —
     * used later to prefer familiar faces when filling a group.
     *
     * @return array<int, array{bot_guid: int, exchanges: int, last_seen: string}>
     */
    public function familiarBots(int $playerGuid, int $limit = 20): array
    {
        return array_map(static fn (array $r): array => [
            'bot_guid' => (int) $r['bot_guid'],
            'exchanges' => (int) $r['exchanges'],
            'last_seen' => (string) $r['last_seen'],
        ], $this->connection()->fetchAllAssociative(
            'SELECT bot_guid, exchanges, last_seen FROM affinity
             WHERE player_guid = :player ORDER BY exchanges DESC, last_seen DESC LIMIT :limit',
            ['player' => $playerGuid, 'limit' => $limit],
        ));
    }

    public function recentlyActiveBots(int $withinSeconds): array
    {
        $since = (new \DateTimeImmutable())->modify(\sprintf('-%d seconds', $withinSeconds));

        return array_map(intval(...), $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT bot_guid FROM conversation WHERE said_at >= :since',
            ['since' => $since->format('Y-m-d H:i:s')],
        ));
    }

    private function touchAffinity(int $botGuid, int $playerGuid): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO affinity (bot_guid, player_guid, exchanges, last_seen)
             VALUES (:bot, :player, 1, :now)
             ON CONFLICT(bot_guid, player_guid)
             DO UPDATE SET exchanges = exchanges + 1, last_seen = :now',
            ['bot' => $botGuid, 'player' => $playerGuid, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /**
     * One row of transcript, with the shape callers are promised.
     *
     * The driver hands back array<string, mixed>; saying so in the docblock and then returning
     * it unchanged is a promise nothing checks.
     *
     * @param array<string, mixed> $row
     *
     * @return array{speaker: string, message: string, said_at: string}
     */
    private static function asLine(array $row): array
    {
        return [
            'speaker' => (string) $row['speaker'],
            'message' => (string) $row['message'],
            'said_at' => (string) $row['said_at'],
        ];
    }

    private function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        if (!is_dir($dir = \dirname($this->path))) {
            mkdir($dir, 0o775, true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
        $this->migrate($connection);

        return $this->connection = $connection;
    }

    /**
     * Created in code rather than shipped as migrations: this file is a local cache of a running
     * game, not a system of record, and a fresh clone should need no setup step.
     */
    private function migrate(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS conversation (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bot_guid INTEGER NOT NULL,
                player_guid INTEGER NOT NULL,
                speaker TEXT NOT NULL,
                message TEXT NOT NULL,
                said_at TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_conversation_pair ON conversation (bot_guid, player_guid, id)',
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS scene (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scene_key TEXT NOT NULL,
                speaker_guid INTEGER NOT NULL,
                speaker TEXT NOT NULL,
                message TEXT NOT NULL,
                said_at TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_scene ON scene (scene_key, id)',
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS affinity (
                bot_guid INTEGER NOT NULL,
                player_guid INTEGER NOT NULL,
                exchanges INTEGER NOT NULL DEFAULT 0,
                last_seen TEXT NOT NULL,
                PRIMARY KEY (bot_guid, player_guid)
            )',
        );
    }
}
