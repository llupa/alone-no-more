<?php

declare(strict_types=1);

namespace App\World\Chat;

use App\World\Model\ChatEvent;

/**
 * Everything said in the world, as our server module captured it.
 *
 * An interface so a recorded session can be played back without a running server: the listener
 * and the MCP server both read chat through this, and neither can tell the difference.
 */
interface ChatEventRepositoryInterface
{
    /**
     * Lines newer than a watermark, oldest first.
     *
     * @return ChatEvent[]
     */
    public function since(int $watermark, int $limit = 50): array;

    /**
     * The newest line's id, for starting a listener without replaying history.
     */
    /**
     * The id of the first line said at or after a moment.
     *
     * For capturing a session rather than "the last sixty lines, whatever they were": the
     * table is timestamped, so a wall-clock answer can be turned into the watermark that
     * {@see since()} already understands.
     *
     * @param string $at anything \DateTimeImmutable accepts — "2026-11-16 20:00", "-2 hours"
     *
     * @return int|null null when nothing was said after that moment
     */
    public function firstIdSince(string $at): ?int;

    public function latestId(): int;

    /**
     * @return ChatEvent[]
     */
    public function conversationBetween(int $botGuid, int $playerGuid, int $limit = 20): array;
}
