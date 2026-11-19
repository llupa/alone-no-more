<?php

declare(strict_types=1);

namespace App\Replay;

use App\World\OutboxInterface;

/**
 * The worldserver's queue, in a world with no worldserver.
 *
 * Speech is accepted, because a reply in a recorded conversation is still a reply — it simply
 * reaches the terminal instead of a chat window. Anything a caller waits on is reported as not
 * done, with the reason, so an order is never announced as carried out when nothing could carry
 * it out. The same honesty as ReplayGateway::execute(), one layer down.
 */
final class ReplayOutbox implements OutboxInterface
{
    public const NOT_CARRIED_OUT = 'this is a recorded world, so nothing reaches a game server';

    private int $nextId = 1;

    public function queue(int $botGuid, string $action, string $message, int $targetGuid = 0): int
    {
        return $this->nextId++;
    }

    public function awaitOutcome(int $outboxId, float $seconds = 2.0): array
    {
        return ['done' => false, 'error' => self::NOT_CARRIED_OUT];
    }

    public function latest(int $botGuid, string $action): ?array
    {
        return null;
    }

    public function statusOf(int $outboxId): ?string
    {
        return null;
    }
}
