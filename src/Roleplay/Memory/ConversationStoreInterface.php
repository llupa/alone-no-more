<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\World\Model\ChatEvent;

/**
 * What each bot remembers of each player.
 *
 * Separated from the SQLite implementation so the selector's cooldown and rotation rules can be
 * tested without touching a file.
 */
interface ConversationStoreInterface
{
    public function remember(int $botGuid, int $playerGuid, string $speaker, string $message): void;

    public function rememberExchange(ChatEvent $event, int $botGuid, string $reply, string $botName): void;

    /**
     * @return array<int, array{speaker: string, message: string, said_at: string}>
     */
    public function recent(int $botGuid, int $playerGuid, int $limit = 20): array;

    public function hasSpokenBefore(int $botGuid, int $playerGuid): bool;

    public function rememberHeard(string $sceneKey, int $speakerGuid, string $speaker, string $message): void;

    /**
     * What was said in a scene, oldest first, leaving out the given speakers' own lines.
     *
     * @param int[] $excludingGuids
     *
     * @return array<int, array{speaker: string, message: string, said_at: string}>
     */
    public function sceneLines(string $sceneKey, array $excludingGuids, int $limit = 12): array;

    /**
     * How well a bot knows a player, or null if they have never spoken.
     *
     * @return array{exchanges: int, last_seen: string}|null
     */
    public function acquaintance(int $botGuid, int $playerGuid): ?array;

    /**
     * Seconds since this bot last spoke, or null if it never has.
     */
    public function secondsSinceLastReply(int $botGuid, string $botName): ?int;

    /**
     * @return array<int, array{bot_guid: int, exchanges: int, last_seen: string}>
     */
    public function familiarBots(int $playerGuid, int $limit = 20): array;

    /**
     * Bots that have said something in the last few minutes, whoever they said it to.
     *
     * Used to keep the herder from logging out someone the player is mid-conversation with —
     * a companion vanishing between your question and its answer reads as the world breaking.
     *
     * @return int[] bot guids
     */
    public function recentlyActiveBots(int $withinSeconds): array;
}
