<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * What a character is working on.
 *
 * Separated from the SQLite-and-MySQL implementation for the same reason as the conversation
 * store: how a companion phrases "four more belts" is worth testing without a database.
 */
interface QuestLogInterface
{
    /**
     * @return array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}>
     */
    public function activeFor(int $guid, int $limit = 25): array;

    public function hasCompleted(int $guid, int $questId): bool;
}
