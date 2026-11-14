<?php

declare(strict_types=1);

namespace App\World;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The queue mod-anm-bridge drains on the world tick, every 500 ms.
 *
 * Anything that has to happen to a `Player` object has to happen inside the worldserver, and a
 * PHP process cannot touch one. Speech was the first such thing; leading a companion somewhere
 * is the second. Both are the same mechanism, so it lives here rather than twice.
 */
final readonly class Outbox
{
    public function __construct(
        #[Autowire(service: 'anm.characters_connection')]
        private Connection $connection,
    ) {
    }

    /**
     * @return int the row id, so the caller can follow up on delivery
     */
    public function queue(int $botGuid, string $action, string $message, int $targetGuid = 0): int
    {
        $this->connection->insert('anm_outbox', [
            'bot_guid' => $botGuid,
            'action' => $action,
            'target_guid' => $targetGuid,
            'message' => $message,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Wait for the module to report back.
     *
     * Worth waiting for, because the module knows things this side cannot: whether the bot was
     * online, whether the destination parsed. A companion that announces an action the server
     * refused is the failure mode this exists to prevent — it happened with attacking, where
     * the module wrote "cannot be attacked" into the outbox and nobody read it.
     *
     * @return array{done: bool, error: string} error is empty when it worked
     */
    public function awaitOutcome(int $outboxId, float $seconds = 2.0): array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $row = $this->connection->fetchAssociative(
                'SELECT status, error FROM anm_outbox WHERE id = :id',
                ['id' => $outboxId],
            );

            if (false !== $row && 'pending' !== $row['status']) {
                return ['done' => 'done' === $row['status'], 'error' => (string) ($row['error'] ?? '')];
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return ['done' => false, 'error' => ''];
    }

    public function statusOf(int $outboxId): ?string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM anm_outbox WHERE id = :id',
            ['id' => $outboxId],
        );

        return false === $status ? null : (string) $status;
    }
}
