<?php

declare(strict_types=1);

namespace App\World;

/**
 * The queue of things only the worldserver can do: make a bot speak, attack, log in, walk.
 *
 * An interface because a recording has no worldserver. Replay answers the same calls honestly —
 * accepted, and never carried out — instead of failing on a database that is not there.
 */
interface OutboxInterface
{
    /**
     * @return int the row id, so the caller can follow up on delivery
     */
    public function queue(int $botGuid, string $action, string $message, int $targetGuid = 0): int;

    /**
     * Wait for the module to report back.
     *
     * @return array{done: bool, error: string} error is empty when it worked
     */
    public function awaitOutcome(int $outboxId, float $seconds = 2.0): array;

    /**
     * How the latest order of one kind for a bot turned out.
     *
     * @return array{status: string, error: string}|null null when no such order was ever sent
     */
    public function latest(int $botGuid, string $action): ?array;

    public function statusOf(int $outboxId): ?string;
}
