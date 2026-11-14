<?php

declare(strict_types=1);

namespace App\Director;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Companions asked for in advance, waiting for the player to turn up.
 *
 * "I want to play with Doppel later and I want to pair with a priest of my level" is a sentence
 * the herder cannot answer: it assembles a crowd by level and faction, deliberately anonymously,
 * and anyone in it may be dismissed when the player moves on. A named request is the opposite —
 * one specific character, chosen on purpose, kept.
 *
 * Written down rather than held in memory, because the whole point is "later": the request has
 * to survive the listener restarting, and usually the night.
 *
 * The bot is picked when the request is made, not when it is honoured. That is what lets an
 * assistant answer with a name — "Aelthil will be waiting" — instead of a promise.
 */
final class CompanionRequests
{
    private ?Connection $connection = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/anm.sqlite')]
        private readonly string $path,
    ) {
    }

    public function add(int $forGuid, string $forCharacter, int $botGuid, string $botName, string $wanted): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO companion_request (for_guid, for_character, bot_guid, bot_name, wanted, asked_at)
             VALUES (:forGuid, :for, :guid, :name, :wanted, :at)
             ON CONFLICT(for_guid, bot_guid) DO UPDATE SET wanted = excluded.wanted, for_character = excluded.for_character',
            [
                'forGuid' => $forGuid,
                'for' => $forCharacter,
                'guid' => $botGuid,
                'name' => $botName,
                'wanted' => $wanted,
                'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array<int, array{bot_guid: int, bot_name: string, wanted: string}>
     */
    /**
     * @return array<int, array{bot_guid: int, bot_name: string, wanted: string}>
     */
    public function forCharacter(int $guid): array
    {
        return array_map(static fn (array $r): array => [
            'bot_guid' => (int) $r['bot_guid'],
            'bot_name' => (string) $r['bot_name'],
            'wanted' => (string) $r['wanted'],
        ], $this->connection()->fetchAllAssociative(
            'SELECT bot_guid, bot_name, wanted FROM companion_request WHERE for_guid = :for ORDER BY asked_at',
            ['for' => $guid],
        ));
    }

    public function forget(int $forGuid, string $botName): bool
    {
        return 0 < $this->connection()->executeStatement(
            'DELETE FROM companion_request WHERE for_guid = :for AND LOWER(bot_name) = :name',
            ['for' => $forGuid, 'name' => strtolower($botName)],
        );
    }

    public function forgetAll(int $forGuid): int
    {
        return (int) $this->connection()->executeStatement(
            'DELETE FROM companion_request WHERE for_guid = :for',
            ['for' => $forGuid],
        );
    }

    private function connection(): Connection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        if (!is_dir($dir = \dirname($this->path))) {
            mkdir($dir, 0o775, true);
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
        $this->connection->executeStatement(
            // Keyed on guid, not name. A character rename keeps the guid and changes the name,
            // so a name-keyed request would be orphaned the moment someone renamed — and
            // orphaned *silently*, which is the worst kind: the companion simply never turns up
            // and nothing explains why. The name is kept alongside for display only.
            'CREATE TABLE IF NOT EXISTS companion_request (
                for_guid INTEGER NOT NULL,
                for_character TEXT NOT NULL,
                bot_guid INTEGER NOT NULL,
                bot_name TEXT NOT NULL,
                wanted TEXT NOT NULL,
                asked_at TEXT NOT NULL,
                PRIMARY KEY (for_guid, bot_guid)
            )',
        );

        return $this->connection;
    }
}
