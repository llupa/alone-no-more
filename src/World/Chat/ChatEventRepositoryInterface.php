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
    public function latestId(): int;

    /**
     * @return ChatEvent[]
     */
    public function conversationBetween(int $botGuid, int $playerGuid, int $limit = 20): array;
}
